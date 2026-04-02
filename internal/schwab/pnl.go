package schwab

import (
	"context"
	"fmt"
	"math"
	"sort"
	"strconv"
	"strings"
	"time"
)

// ClosedTrade represents a completed round-trip trade with realized P&L
type ClosedTrade struct {
	ActivityID       int64
	Symbol           string
	CloseTime        time.Time
	Quantity         float64
	OpenCash         float64 // cash flow from opening (negative for longs, positive for shorts)
	CloseCash        float64 // cash flow from closing (positive for longs, negative for shorts)
	RealizedPnL      float64
	ClosePrice       float64
	CloseInstruction string // e.g. "SELL", "BUY_TO_CLOSE"
	AssetType        string // "EQUITY", "OPTION", or "FUTURE"
	MatchedOpenings  []MatchedOpening
}

// MatchedOpening captures an opening lot used to realize a closing trade.
type MatchedOpening struct {
	OpenActivityID  int64
	OpenTime        time.Time
	Quantity        float64
	OpenCash        float64
	OpenCashPerUnit float64
	OpenPrice       float64
	OpenInstruction string // e.g. "BUY", "SELL_TO_OPEN"
}

// PnLSummary contains realized P&L for a time period
type PnLSummary struct {
	TotalGain  float64
	TotalLoss  float64
	NetGain    float64
	TradeCount int
	Trades     []ClosedTrade
	Warnings   []string
}

// parsedTrade is the internal representation of a transaction
type parsedTrade struct {
	activityID     int64
	positionID     int64
	time           time.Time
	symbol         string
	qty            float64 // always positive
	netAmount      float64 // total cash flow including fees (sign preserved)
	price          float64 // per-unit execution price
	positionEffect string  // "OPENING" or "CLOSING"
	assetType      string  // "EQUITY" or "OPTION"
	inRange        bool    // true if this trade is in the target date range
}

// instruction returns the full trade instruction (e.g. "BUY", "SELL_TO_CLOSE")
// derived from positionEffect, netAmount sign, and asset type.
func (t *parsedTrade) instruction() string {
	isBuy := t.netAmount < 0 // negative cash = buying
	if t.assetType == "OPTION" {
		if t.positionEffect == "OPENING" {
			if isBuy {
				return "BUY_TO_OPEN"
			}
			return "SELL_TO_OPEN"
		}
		if isBuy {
			return "BUY_TO_CLOSE"
		}
		return "SELL_TO_CLOSE"
	}
	if isBuy {
		return "BUY"
	}
	return "SELL"
}

// groupKey returns the key for grouping trades by symbol.
// We group by symbol rather than positionId because the API often returns
// positionId=0 for some trades but non-zero for others in the same position,
// which would split them into separate FIFO queues and prevent matching.
// Each option contract has a unique symbol (e.g., "AAPL  260220C00270000"),
// so symbol-based grouping correctly separates different instruments.
func (t *parsedTrade) groupKey() string {
	return t.symbol
}

// lot represents an open lot in the FIFO inventory
type lot struct {
	openActivityID  int64
	openTime        time.Time
	cashPerUnit     float64
	qtyRemain       float64
	price           float64
	openInstruction string
}

// TransactionFetcher abstracts the data source for account and transaction data.
// Both schwab.Client and broker.Client implement this interface.
type TransactionFetcher interface {
	GetAccountNumbers(ctx context.Context) ([]AccountNumber, error)
	GetTransactions(ctx context.Context, accountHash string, startDate, endDate time.Time, txnType string) ([]Transaction, error)
}

const (
	historyChunkMonths = 6
	maxHistoryMonths   = 36
	maxNoProgress      = 2
	matchEpsilon       = 1e-9
)

// CalculateRealizedPnL calculates realized P&L using a running FIFO inventory.
// It first loads all trades in the requested date range, then progressively
// walks backwards in time until every in-range closing trade can be fully
// matched against opening lots. This avoids partial matching caused by a
// fixed lookback horizon.
func CalculateRealizedPnL(ctx context.Context, ds TransactionFetcher, accountHash string, fromDate, toDate time.Time) (*PnLSummary, error) {
	loc := fromDate.Location()
	fromDay := time.Date(fromDate.Year(), fromDate.Month(), fromDate.Day(), 0, 0, 0, 0, loc)
	toDay := time.Date(toDate.Year(), toDate.Month(), toDate.Day(), 0, 0, 0, 0, loc)
	if toDate.After(toDay) {
		toDay = toDay.AddDate(0, 0, 1)
	}

	fetchAllTrades := func(from, to time.Time) ([]Transaction, error) {
		trades, err := ds.GetTransactions(ctx, accountHash, from, to, "TRADE")
		if err != nil {
			return nil, err
		}
		expirations, err := ds.GetTransactions(ctx, accountHash, from, to, "RECEIVE_AND_DELIVER")
		if err == nil {
			trades = append(trades, expirations...)
		}
		return trades, nil
	}

	var inRangeTrades []parsedTrade

	// Fetch target range day by day (avoids potential API range limits).
	for day := fromDay; day.Before(toDay); day = day.AddDate(0, 0, 1) {
		dayEnd := day.AddDate(0, 0, 1)
		txns, err := fetchAllTrades(day, dayEnd)
		if err != nil {
			return nil, fmt.Errorf("failed to get transactions for %s: %w", day.Format("2006-01-02"), err)
		}
		for _, t := range parseTrades(txns) {
			t.inRange = true
			inRangeTrades = append(inRangeTrades, t)
		}
	}
	if len(inRangeTrades) == 0 {
		return &PnLSummary{}, nil
	}

	// Keep only symbols that have closings in-range.
	neededSymbols := make(map[string]struct{})
	for _, t := range inRangeTrades {
		if t.positionEffect == "CLOSING" {
			neededSymbols[t.groupKey()] = struct{}{}
		}
	}
	if len(neededSymbols) == 0 {
		return &PnLSummary{}, nil
	}

	filteredInRange := make([]parsedTrade, 0, len(inRangeTrades))
	for _, t := range inRangeTrades {
		if _, ok := neededSymbols[t.groupKey()]; ok {
			filteredInRange = append(filteredInRange, t)
		}
	}
	inRangeTrades = filteredInRange

	// Iteratively expand lookback history until all in-range closes match, or
	// we stop making progress.
	historyStart := fromDay
	var historyTrades []parsedTrade
	bestSummary := &PnLSummary{}
	prevUnmatchedQty := math.MaxFloat64
	noProgressRuns := 0

	for monthsBack := 0; monthsBack <= maxHistoryMonths; monthsBack += historyChunkMonths {
		allTrades := make([]parsedTrade, 0, len(historyTrades)+len(inRangeTrades))
		allTrades = append(allTrades, historyTrades...)
		allTrades = append(allTrades, inRangeTrades...)
		sortParsedTrades(allTrades)

		summary, unmatched := summarizeMatchedTrades(allTrades)
		bestSummary = summary
		if len(unmatched) == 0 {
			return summary, nil
		}

		curUnmatchedQty := totalUnmatchedQty(unmatched)
		if curUnmatchedQty >= prevUnmatchedQty-matchEpsilon {
			noProgressRuns++
		} else {
			noProgressRuns = 0
		}
		prevUnmatchedQty = curUnmatchedQty
		if noProgressRuns >= maxNoProgress || monthsBack == maxHistoryMonths {
			bestSummary.Warnings = append(bestSummary.Warnings,
				fmt.Sprintf("Some closing trades could not be fully matched to openings; results may be incomplete (%s)", formatUnmatched(unmatched)))
			return bestSummary, nil
		}

		chunkFrom := historyStart.AddDate(0, -historyChunkMonths, 0)
		txns, err := fetchAllTrades(chunkFrom, historyStart)
		if err != nil {
			return nil, fmt.Errorf("failed to get history transactions for %s..%s: %w",
				chunkFrom.Format("2006-01-02"), historyStart.Format("2006-01-02"), err)
		}

		for _, t := range parseTrades(txns) {
			if _, ok := unmatched[t.groupKey()]; !ok {
				continue
			}
			t.inRange = false
			historyTrades = append(historyTrades, t)
		}
		historyStart = chunkFrom
	}

	return bestSummary, nil
}

func sortParsedTrades(allTrades []parsedTrade) {
	// Sort chronologically; within same timestamp, OPENING before CLOSING
	// so that lots are available when the close is processed.
	sort.Slice(allTrades, func(i, j int) bool {
		if allTrades[i].time.Equal(allTrades[j].time) {
			if allTrades[i].positionEffect != allTrades[j].positionEffect {
				return allTrades[i].positionEffect == "OPENING"
			}
			return allTrades[i].activityID < allTrades[j].activityID
		}
		return allTrades[i].time.Before(allTrades[j].time)
	})
}

func summarizeMatchedTrades(allTrades []parsedTrade) (*PnLSummary, map[string]float64) {
	inventory := make(map[string][]lot)
	var closedTrades []ClosedTrade
	unmatched := make(map[string]float64)

	for _, t := range allTrades {
		if t.qty <= 0 {
			continue
		}
		key := t.groupKey()
		if t.positionEffect == "OPENING" {
			inventory[key] = append(inventory[key], lot{
				openActivityID:  t.activityID,
				openTime:        t.time,
				cashPerUnit:     t.netAmount / t.qty,
				qtyRemain:       t.qty,
				price:           t.price,
				openInstruction: t.instruction(),
			})
			continue
		}
		if t.positionEffect != "CLOSING" {
			continue
		}

		remaining := t.qty
		closeCashPerUnit := t.netAmount / t.qty
		var openCashTotal float64
		var matchedOpenings []MatchedOpening
		lots := inventory[key]

		// Build list of available lot indices sorted by P&L impact.
		type candidate struct {
			idx   int
			pnlPU float64
		}
		var candidates []candidate
		for i, l := range lots {
			if l.qtyRemain > matchEpsilon {
				candidates = append(candidates, candidate{i, l.cashPerUnit + closeCashPerUnit})
			}
		}
		sort.Slice(candidates, func(a, b int) bool {
			return candidates[a].pnlPU < candidates[b].pnlPU // most loss first
		})

		for _, c := range candidates {
			if remaining <= matchEpsilon {
				break
			}
			matched := math.Min(remaining, lots[c.idx].qtyRemain)
			openCashTotal += lots[c.idx].cashPerUnit * matched
			matchedOpenings = append(matchedOpenings, MatchedOpening{
				OpenActivityID:  lots[c.idx].openActivityID,
				OpenTime:        lots[c.idx].openTime,
				Quantity:        matched,
				OpenCash:        lots[c.idx].cashPerUnit * matched,
				OpenCashPerUnit: lots[c.idx].cashPerUnit,
				OpenPrice:       lots[c.idx].price,
				OpenInstruction: lots[c.idx].openInstruction,
			})
			lots[c.idx].qtyRemain -= matched
			remaining -= matched
		}
		inventory[key] = lots

		if remaining > matchEpsilon {
			if t.inRange {
				unmatched[key] += remaining
			}
			continue
		}

		if t.inRange {
			pnl := openCashTotal + t.netAmount
			closedTrades = append(closedTrades, ClosedTrade{
				ActivityID:       t.activityID,
				Symbol:           t.symbol,
				CloseTime:        t.time,
				Quantity:         t.qty,
				OpenCash:         openCashTotal,
				CloseCash:        t.netAmount,
				RealizedPnL:      pnl,
				ClosePrice:       t.price,
				CloseInstruction: t.instruction(),
				AssetType:        t.assetType,
				MatchedOpenings:  matchedOpenings,
			})
		}
	}

	summary := &PnLSummary{
		Trades:     closedTrades,
		TradeCount: len(closedTrades),
	}
	for _, trade := range closedTrades {
		if trade.RealizedPnL >= 0 {
			summary.TotalGain += trade.RealizedPnL
		} else {
			summary.TotalLoss += trade.RealizedPnL
		}
	}
	summary.NetGain = summary.TotalGain + summary.TotalLoss

	return summary, unmatched
}

func formatUnmatched(unmatched map[string]float64) string {
	if len(unmatched) == 0 {
		return ""
	}
	type kv struct {
		symbol string
		qty    float64
	}
	items := make([]kv, 0, len(unmatched))
	for sym, qty := range unmatched {
		items = append(items, kv{symbol: sym, qty: qty})
	}
	sort.Slice(items, func(i, j int) bool { return items[i].symbol < items[j].symbol })
	parts := make([]string, 0, len(items))
	for _, item := range items {
		parts = append(parts, fmt.Sprintf("%s qty=%0.4f", item.symbol, item.qty))
		if len(parts) == 5 {
			break
		}
	}
	if len(items) > 5 {
		parts = append(parts, "...")
	}
	return strings.Join(parts, ", ")
}

func totalUnmatchedQty(unmatched map[string]float64) float64 {
	var total float64
	for _, qty := range unmatched {
		total += qty
	}
	return total
}

// parseTrades extracts structured trade data from raw transactions
func parseTrades(txns []Transaction) []parsedTrade {
	var trades []parsedTrade

	for _, txn := range txns {
		t := parsedTrade{
			activityID: txn.ActivityID,
			positionID: txn.PositionID,
			netAmount:  txn.NetAmount,
		}

		t.time, _ = time.Parse("2006-01-02T15:04:05+0000", txn.Time)

		for _, item := range txn.TransferItems {
			if item.Instrument.AssetType != "CURRENCY" {
				t.symbol = item.Instrument.Symbol
				t.qty = math.Abs(item.Amount)
				t.price = item.Price
				t.positionEffect = item.PositionEffect
				t.assetType = item.Instrument.AssetType
			}
		}

		if t.qty > 0 && t.positionEffect != "" {
			trades = append(trades, t)
		}
	}

	return trades
}

// JSON output types for --json flag

type JSONOutput struct {
	Timeframes []JSONTimeframe `json:"timeframes"`
	Accounts   []string        `json:"accounts"`
	AccountIDs []string        `json:"account_ids"`
	Warnings   []string        `json:"warnings"`
	UpdatedAt  string          `json:"updated_at"`
}

type JSONTimeframe struct {
	Label      string       `json:"label"`
	Gains      float64      `json:"gains"`
	Losses     float64      `json:"losses"`
	Net        float64      `json:"net"`
	TradeCount int          `json:"trade_count"`
	Tickers    []JSONTicker `json:"tickers"`
}

type JSONTicker struct {
	Symbol     string      `json:"symbol"`
	Display    string      `json:"display"`
	Type       string      `json:"type"`
	Underlying string      `json:"underlying,omitempty"`
	Expiry     string      `json:"expiry,omitempty"`
	Strike     float64     `json:"strike,omitempty"`
	OptionType string      `json:"option_type,omitempty"`
	Net        float64     `json:"net"`
	TradeCount int         `json:"trade_count"`
	Closes     []JSONClose `json:"closes"`
}

type JSONClose struct {
	Time         string            `json:"time"`
	Side         string            `json:"side"`
	Quantity     float64           `json:"quantity"`
	Price        float64           `json:"price"`
	PnL          float64           `json:"pnl"`
	MatchedOpens []JSONMatchedOpen `json:"matched_opens"`
}

type JSONMatchedOpen struct {
	Time     string  `json:"time"`
	Side     string  `json:"side"`
	Quantity float64 `json:"quantity"`
	Price    float64 `json:"price"`
}

// ParseOCCSymbol parses an OCC option symbol (e.g., "HD    250307C00380000")
// into its components. Returns ok=false if not a valid OCC symbol.
func ParseOCCSymbol(symbol string) (underlying, expiry string, strike float64, optionType string, ok bool) {
	// OCC format: 6-char padded underlying + YYMMDD + C/P + 8-digit strike (thousandths)
	if len(symbol) != 21 {
		return "", "", 0, "", false
	}

	underlying = strings.TrimSpace(symbol[:6])
	if underlying == "" {
		return "", "", 0, "", false
	}

	dateStr := symbol[6:12]
	cpFlag := symbol[12:13]
	strikeStr := symbol[13:21]

	yy, err1 := strconv.Atoi(dateStr[:2])
	mm, err2 := strconv.Atoi(dateStr[2:4])
	dd, err3 := strconv.Atoi(dateStr[4:6])
	if err1 != nil || err2 != nil || err3 != nil || mm < 1 || mm > 12 || dd < 1 || dd > 31 {
		return "", "", 0, "", false
	}
	expiry = fmt.Sprintf("20%02d-%02d-%02d", yy, mm, dd)

	switch cpFlag {
	case "C":
		optionType = "CALL"
	case "P":
		optionType = "PUT"
	default:
		return "", "", 0, "", false
	}

	strikeInt, err := strconv.Atoi(strikeStr)
	if err != nil {
		return "", "", 0, "", false
	}
	strike = float64(strikeInt) / 1000.0

	return underlying, expiry, strike, optionType, true
}
