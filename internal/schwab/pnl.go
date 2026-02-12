package schwab

import (
	"context"
	"fmt"
	"math"
	"sort"
	"time"
)

// ClosedTrade represents a completed round-trip trade with realized P&L
type ClosedTrade struct {
	Symbol      string
	CloseTime   time.Time
	Quantity    float64
	OpenCash    float64 // cash flow from opening (negative for longs, positive for shorts)
	CloseCash   float64 // cash flow from closing (positive for longs, negative for shorts)
	RealizedPnL float64
}

// PnLSummary contains realized P&L for a time period
type PnLSummary struct {
	TotalGain  float64
	TotalLoss  float64
	NetGain    float64
	TradeCount int
	Trades     []ClosedTrade
}

// parsedTrade is the internal representation of a transaction
type parsedTrade struct {
	activityID     int64
	positionID     int64
	time           time.Time
	symbol         string
	qty            float64 // always positive
	netAmount      float64 // total cash flow including fees (sign preserved)
	positionEffect string  // "OPENING" or "CLOSING"
	inRange        bool    // true if this trade is in the target date range
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
	cashPerUnit float64
	qtyRemain   float64
}

// CalculateRealizedPnL calculates realized P&L using a running FIFO inventory.
// It fetches a 3-month lookback to build initial inventory of open lots, then
// processes the target range chronologically maintaining lot state across days.
func (c *Client) CalculateRealizedPnL(ctx context.Context, accountHash string, fromDate, toDate time.Time) (*PnLSummary, error) {
	loc := fromDate.Location()
	fromDay := time.Date(fromDate.Year(), fromDate.Month(), fromDate.Day(), 0, 0, 0, 0, loc)
	toDay := time.Date(toDate.Year(), toDate.Month(), toDate.Day(), 0, 0, 0, 0, loc)
	if toDate.After(toDay) {
		toDay = toDay.AddDate(0, 0, 1)
	}

	// fetchAllTrades fetches both TRADE and RECEIVE_AND_DELIVER (option expirations)
	// for the given date range. Expirations are closing trades with netAmount=0,
	// and the realized loss is the premium paid on the opening trade.
	fetchAllTrades := func(from, to time.Time) ([]Transaction, error) {
		trades, err := c.GetTransactions(ctx, accountHash, from, to, "TRADE")
		if err != nil {
			return nil, err
		}
		expirations, err := c.GetTransactions(ctx, accountHash, from, to, "RECEIVE_AND_DELIVER")
		if err == nil {
			trades = append(trades, expirations...)
		}
		return trades, nil
	}

	var allTrades []parsedTrade

	// Fetch lookback data (3 months before) to build initial inventory.
	// This captures opening trades for positions that were opened before the
	// target range but closed within it. Closings during lookback are also
	// processed to correctly reduce the inventory.
	lookbackFrom := fromDay.AddDate(0, -3, 0)
	lookbackTxns, err := fetchAllTrades(lookbackFrom, fromDay)
	if err == nil {
		for _, t := range parseTrades(lookbackTxns) {
			t.inRange = false
			allTrades = append(allTrades, t)
		}
	}

	// Fetch target range day by day (avoids potential API range limits)
	for day := fromDay; day.Before(toDay); day = day.AddDate(0, 0, 1) {
		if day.Weekday() == time.Saturday || day.Weekday() == time.Sunday {
			continue
		}
		dayEnd := day.AddDate(0, 0, 1)
		txns, err := fetchAllTrades(day, dayEnd)
		if err != nil {
			return nil, fmt.Errorf("failed to get transactions for %s: %w", day.Format("2006-01-02"), err)
		}
		for _, t := range parseTrades(txns) {
			t.inRange = true
			allTrades = append(allTrades, t)
		}
	}

	// Sort chronologically; within same timestamp, OPENING before CLOSING
	// so that lots are available when the close is processed
	sort.Slice(allTrades, func(i, j int) bool {
		if allTrades[i].time.Equal(allTrades[j].time) {
			if allTrades[i].positionEffect != allTrades[j].positionEffect {
				return allTrades[i].positionEffect == "OPENING"
			}
			return allTrades[i].activityID < allTrades[j].activityID
		}
		return allTrades[i].time.Before(allTrades[j].time)
	})

	// Process all trades with running FIFO inventory
	inventory := make(map[string][]lot)
	var closedTrades []ClosedTrade

	for _, t := range allTrades {
		key := t.groupKey()
		if t.positionEffect == "OPENING" {
			inventory[key] = append(inventory[key], lot{
				cashPerUnit: t.netAmount / t.qty,
				qtyRemain:   t.qty,
			})
		} else {
			// CLOSING: match against inventory using tax-optimized order.
			// Schwab's Tax Lot Optimizer selects lots to maximize losses
			// (minimize realized P&L). For each closing trade, we sort
			// available lots by the P&L they would produce (ascending)
			// so the lot producing the biggest loss is matched first.
			remaining := t.qty
			closeCashPerUnit := t.netAmount / t.qty
			var openCashTotal float64

			lots := inventory[key]

			// Build list of available lot indices sorted by P&L impact
			type candidate struct {
				idx   int
				pnlPU float64 // P&L per unit if this lot is used
			}
			var candidates []candidate
			for i, l := range lots {
				if l.qtyRemain > 0 {
					candidates = append(candidates, candidate{i, l.cashPerUnit + closeCashPerUnit})
				}
			}
			sort.Slice(candidates, func(a, b int) bool {
				return candidates[a].pnlPU < candidates[b].pnlPU // most loss first
			})

			for _, c := range candidates {
				if remaining <= 0 {
					break
				}
				matched := math.Min(remaining, lots[c.idx].qtyRemain)
				openCashTotal += lots[c.idx].cashPerUnit * matched
				lots[c.idx].qtyRemain -= matched
				remaining -= matched
			}
			inventory[key] = lots

			matchedQty := t.qty - remaining

			// Only emit P&L for closing trades within the target date range
			if t.inRange && matchedQty > 0 {
				closeCashTotal := closeCashPerUnit * matchedQty
				pnl := openCashTotal + closeCashTotal
				closedTrades = append(closedTrades, ClosedTrade{
					Symbol:      t.symbol,
					CloseTime:   t.time,
					Quantity:    matchedQty,
					OpenCash:    openCashTotal,
					CloseCash:   closeCashTotal,
					RealizedPnL: pnl,
				})
			}
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

	return summary, nil
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
				t.positionEffect = item.PositionEffect
			}
		}

		if t.qty > 0 && t.positionEffect != "" {
			trades = append(trades, t)
		}
	}

	return trades
}
