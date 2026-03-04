package schwab

import (
	"math"
	"testing"
	"time"
)

func TestParseTradesFiltersAndNormalizes(t *testing.T) {
	t.Parallel()

	txns := []Transaction{
		{
			ActivityID: 10,
			Time:       "2026-02-10T14:30:00+0000",
			NetAmount:  -205.5,
			TransferItems: []TransactionItem{
				{Instrument: Instrument{AssetType: "CURRENCY", Symbol: "USD"}, Amount: -205.5},
				{Instrument: Instrument{AssetType: "EQUITY", Symbol: "AAPL"}, Amount: -2, PositionEffect: "OPENING"},
			},
		},
		{
			ActivityID: 11,
			Time:       "2026-02-10T15:00:00+0000",
			NetAmount:  215.5,
			TransferItems: []TransactionItem{
				{Instrument: Instrument{AssetType: "OPTION", Symbol: "AAPL  260220C00270000"}, Amount: 2, PositionEffect: "CLOSING"},
			},
		},
		{
			ActivityID: 12,
			Time:       "2026-02-10T15:30:00+0000",
			NetAmount:  100,
			TransferItems: []TransactionItem{
				{Instrument: Instrument{AssetType: "EQUITY", Symbol: "MSFT"}, Amount: 1},
			},
		},
	}

	parsed := parseTrades(txns)
	if len(parsed) != 2 {
		t.Fatalf("expected 2 parsed trades, got %d", len(parsed))
	}

	if parsed[0].symbol != "AAPL" || parsed[0].qty != 2 || parsed[0].positionEffect != "OPENING" {
		t.Fatalf("unexpected first trade: %+v", parsed[0])
	}
	if parsed[1].symbol != "AAPL  260220C00270000" || parsed[1].qty != 2 || parsed[1].positionEffect != "CLOSING" {
		t.Fatalf("unexpected second trade: %+v", parsed[1])
	}

	expectedTime := time.Date(2026, time.February, 10, 14, 30, 0, 0, time.UTC)
	if !parsed[0].time.Equal(expectedTime) {
		t.Fatalf("unexpected parsed time: got %s want %s", parsed[0].time, expectedTime)
	}
}

func TestParsedTradeGroupKeyUsesSymbol(t *testing.T) {
	t.Parallel()

	trade := parsedTrade{symbol: "TSLA"}
	if trade.groupKey() != "TSLA" {
		t.Fatalf("unexpected group key: %q", trade.groupKey())
	}
}

func TestSummarizeMatchedTradesRequiresFullHistory(t *testing.T) {
	t.Parallel()

	trades := []parsedTrade{
		{
			activityID:     1,
			time:           time.Date(2026, time.February, 10, 15, 0, 0, 0, time.UTC),
			symbol:         "AAPL",
			qty:            2,
			netAmount:      200,
			positionEffect: "CLOSING",
			inRange:        true,
		},
	}
	sortParsedTrades(trades)

	summary, unmatched := summarizeMatchedTrades(trades)
	if summary.NetGain != 0 || summary.TradeCount != 0 {
		t.Fatalf("expected no summarized trades when history is missing, got %+v", summary)
	}
	if unmatched["AAPL"] != 2 {
		t.Fatalf("expected unmatched qty 2 for AAPL, got %+v", unmatched)
	}
}

func TestSummarizeMatchedTradesWithOpeningHistory(t *testing.T) {
	t.Parallel()

	trades := []parsedTrade{
		{
			activityID:     1,
			time:           time.Date(2026, time.January, 20, 15, 0, 0, 0, time.UTC),
			symbol:         "AAPL",
			qty:            2,
			netAmount:      -150,
			positionEffect: "OPENING",
			inRange:        false,
		},
		{
			activityID:     2,
			time:           time.Date(2026, time.February, 10, 15, 0, 0, 0, time.UTC),
			symbol:         "AAPL",
			qty:            2,
			netAmount:      200,
			positionEffect: "CLOSING",
			inRange:        true,
		},
	}
	sortParsedTrades(trades)

	summary, unmatched := summarizeMatchedTrades(trades)
	if len(unmatched) != 0 {
		t.Fatalf("expected all closings matched, got unmatched=%+v", unmatched)
	}
	if summary.TradeCount != 1 {
		t.Fatalf("expected 1 closed trade, got %d", summary.TradeCount)
	}
	if summary.NetGain != 50 {
		t.Fatalf("expected net gain 50, got %0.2f", summary.NetGain)
	}
}

func TestParseOCCSymbol(t *testing.T) {
	t.Parallel()

	tests := []struct {
		name       string
		symbol     string
		wantOK     bool
		underlying string
		expiry     string
		strike     float64
		optType    string
	}{
		{
			name:       "HD call",
			symbol:     "HD    250307C00380000",
			wantOK:     true,
			underlying: "HD",
			expiry:     "2025-03-07",
			strike:     380.0,
			optType:    "CALL",
		},
		{
			name:       "AAPL put",
			symbol:     "AAPL  260220P00270000",
			wantOK:     true,
			underlying: "AAPL",
			expiry:     "2026-02-20",
			strike:     270.0,
			optType:    "PUT",
		},
		{
			name:       "fractional strike",
			symbol:     "TSLA  250110C00250500",
			wantOK:     true,
			underlying: "TSLA",
			expiry:     "2025-01-10",
			strike:     250.5,
			optType:    "CALL",
		},
		{
			name:   "equity symbol (too short)",
			symbol: "HD",
			wantOK: false,
		},
		{
			name:   "wrong length",
			symbol: "HD    250307C003800000",
			wantOK: false,
		},
		{
			name:   "invalid C/P flag",
			symbol: "HD    250307X00380000",
			wantOK: false,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			t.Parallel()
			underlying, expiry, strike, optType, ok := ParseOCCSymbol(tt.symbol)
			if ok != tt.wantOK {
				t.Fatalf("ParseOCCSymbol(%q): ok=%v, want %v", tt.symbol, ok, tt.wantOK)
			}
			if !ok {
				return
			}
			if underlying != tt.underlying {
				t.Errorf("underlying=%q, want %q", underlying, tt.underlying)
			}
			if expiry != tt.expiry {
				t.Errorf("expiry=%q, want %q", expiry, tt.expiry)
			}
			if math.Abs(strike-tt.strike) > 0.001 {
				t.Errorf("strike=%f, want %f", strike, tt.strike)
			}
			if optType != tt.optType {
				t.Errorf("optType=%q, want %q", optType, tt.optType)
			}
		})
	}
}

func TestPriceFlowsThrough(t *testing.T) {
	t.Parallel()

	trades := []parsedTrade{
		{
			activityID:     1,
			time:           time.Date(2026, time.January, 20, 9, 30, 0, 0, time.UTC),
			symbol:         "HD",
			qty:            50,
			netAmount:      -19000,
			price:          380.00,
			positionEffect: "OPENING",
			inRange:        false,
		},
		{
			activityID:     2,
			time:           time.Date(2026, time.January, 20, 10, 0, 0, 0, time.UTC),
			symbol:         "HD",
			qty:            50,
			netAmount:      19250,
			price:          385.00,
			positionEffect: "CLOSING",
			inRange:        true,
		},
	}
	sortParsedTrades(trades)

	summary, _ := summarizeMatchedTrades(trades)
	if summary.TradeCount != 1 {
		t.Fatalf("expected 1 trade, got %d", summary.TradeCount)
	}
	trade := summary.Trades[0]
	if trade.ClosePrice != 385.00 {
		t.Errorf("ClosePrice=%f, want 385.00", trade.ClosePrice)
	}
	if len(trade.MatchedOpenings) != 1 {
		t.Fatalf("expected 1 matched opening, got %d", len(trade.MatchedOpenings))
	}
	if trade.MatchedOpenings[0].OpenPrice != 380.00 {
		t.Errorf("OpenPrice=%f, want 380.00", trade.MatchedOpenings[0].OpenPrice)
	}
}

func TestParseTradesExtractsPrice(t *testing.T) {
	t.Parallel()

	txns := []Transaction{
		{
			ActivityID: 10,
			Time:       "2026-02-10T14:30:00+0000",
			NetAmount:  -19000,
			TransferItems: []TransactionItem{
				{Instrument: Instrument{AssetType: "CURRENCY", Symbol: "USD"}, Amount: -19000},
				{Instrument: Instrument{AssetType: "EQUITY", Symbol: "HD"}, Amount: -50, Price: 380.00, PositionEffect: "OPENING"},
			},
		},
	}

	parsed := parseTrades(txns)
	if len(parsed) != 1 {
		t.Fatalf("expected 1 parsed trade, got %d", len(parsed))
	}
	if parsed[0].price != 380.00 {
		t.Errorf("price=%f, want 380.00", parsed[0].price)
	}
}
