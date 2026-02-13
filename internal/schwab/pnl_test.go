package schwab

import (
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
