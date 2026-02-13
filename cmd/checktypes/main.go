package main

import (
	"context"
	"fmt"
	"math"
	"os"
	"sort"
	"strings"
	"time"

	"github.com/batjaa/tendies/internal/config"
	"github.com/batjaa/tendies/internal/schwab"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		fmt.Fprintf(os.Stderr, "config load failed: %v\n", err)
		os.Exit(1)
	}
	if strings.TrimSpace(cfg.ClientID) == "" || strings.TrimSpace(cfg.ClientSecret) == "" || strings.TrimSpace(cfg.RedirectURL) == "" {
		fmt.Fprintln(os.Stderr, "missing Schwab credentials in config")
		os.Exit(1)
	}

	token, err := config.LoadToken()
	if err != nil {
		fmt.Fprintf(os.Stderr, "token load failed: %v\n", err)
		os.Exit(1)
	}
	if token == nil {
		fmt.Fprintln(os.Stderr, "no OAuth token in keychain; run `tendies login` first")
		os.Exit(1)
	}

	client := schwab.NewClient(cfg.ClientID, cfg.ClientSecret, cfg.RedirectURL)
	if !token.Valid() {
		newToken, err := client.RefreshToken(context.Background(), token)
		if err != nil {
			fmt.Fprintf(os.Stderr, "refresh failed: %v\n", err)
			os.Exit(1)
		}
		token = newToken
		if err := config.SaveToken(token); err != nil {
			fmt.Fprintf(os.Stderr, "failed to save refreshed token: %v\n", err)
			os.Exit(1)
		}
	}
	client.SetToken(token)

	ctx := context.Background()
	accounts, err := client.GetAccountNumbers(ctx)
	if err != nil {
		fmt.Fprintf(os.Stderr, "failed to load accounts: %v\n", err)
		os.Exit(1)
	}
	if len(accounts) == 0 {
		fmt.Fprintln(os.Stderr, "no accounts returned by API")
		os.Exit(1)
	}
	acc := accounts[0]

	loc := time.Now().Location()
	from := time.Now().In(loc).AddDate(0, 0, -7)
	from = time.Date(from.Year(), from.Month(), from.Day(), 0, 0, 0, 0, loc)
	to := time.Now().In(loc).AddDate(0, 0, 1)
	to = time.Date(to.Year(), to.Month(), to.Day(), 0, 0, 0, 0, loc)

	fetchAllTrades := func(fromDate, toDate time.Time) ([]schwab.Transaction, error) {
		trades, err := client.GetTransactions(ctx, acc.HashValue, fromDate, toDate, "TRADE")
		if err != nil {
			return nil, err
		}
		expirations, err := client.GetTransactions(ctx, acc.HashValue, fromDate, toDate, "RECEIVE_AND_DELIVER")
		if err == nil {
			trades = append(trades, expirations...)
		}
		return trades, nil
	}

	inRangeTxns, err := fetchAllTrades(from, to)
	if err != nil {
		fmt.Fprintf(os.Stderr, "failed to load in-range transactions: %v\n", err)
		os.Exit(1)
	}

	inRangeTrades := parseTrades(inRangeTxns)
	for i := range inRangeTrades {
		inRangeTrades[i].InRange = true
	}
	closings := filterByEffect(inRangeTrades, "CLOSING")

	fmt.Printf("Debug range: %s to %s (%s)\n", from.Format("2006-01-02"), to.Format("2006-01-02"), loc.String())
	fmt.Println()
	fmt.Println("=== Closing Trades In Range ===")
	printTrades(closings)
	fmt.Println()

	symbolSet := make(map[string]struct{})
	for _, t := range closings {
		symbolSet[t.Symbol] = struct{}{}
	}

	var tried []trade
	for _, t := range inRangeTrades {
		if _, ok := symbolSet[t.Symbol]; ok {
			t.InRange = true
			tried = append(tried, t)
		}
	}

	historyStart := from
	const (
		historyChunkMonths = 6
		maxHistoryMonths   = 36
	)
	for monthsBack := 0; monthsBack < maxHistoryMonths; monthsBack += historyChunkMonths {
		chunkFrom := historyStart.AddDate(0, -historyChunkMonths, 0)
		historyTxns, err := fetchAllTrades(chunkFrom, historyStart)
		if err != nil {
			fmt.Fprintf(os.Stderr, "failed to load history chunk %s..%s: %v\n",
				chunkFrom.Format("2006-01-02"), historyStart.Format("2006-01-02"), err)
			os.Exit(1)
		}
		historyTrades := parseTrades(historyTxns)
		added := 0
		for _, t := range historyTrades {
			if _, ok := symbolSet[t.Symbol]; ok {
				t.InRange = false
				tried = append(tried, t)
				added++
			}
		}
		historyStart = chunkFrom
		if added == 0 {
			break
		}
	}

	sort.Slice(tried, func(i, j int) bool {
		if tried[i].Time.Equal(tried[j].Time) {
			if tried[i].PositionEffect != tried[j].PositionEffect {
				return tried[i].PositionEffect == "OPENING"
			}
			return tried[i].ActivityID < tried[j].ActivityID
		}
		return tried[i].Time.Before(tried[j].Time)
	})

	fmt.Println("=== Trades Tried For Matching ===")
	printTrades(tried)
}

type trade struct {
	ActivityID     int64
	Time           time.Time
	Symbol         string
	Qty            float64
	NetAmount      float64
	PositionEffect string
	InRange        bool
}

func parseTrades(txns []schwab.Transaction) []trade {
	var out []trade
	for _, txn := range txns {
		t := trade{
			ActivityID: txn.ActivityID,
			NetAmount:  txn.NetAmount,
		}
		parsedTime, err := time.Parse("2006-01-02T15:04:05+0000", txn.Time)
		if err == nil {
			t.Time = parsedTime
		}
		for _, item := range txn.TransferItems {
			if item.Instrument.AssetType == "CURRENCY" {
				continue
			}
			t.Symbol = item.Instrument.Symbol
			t.Qty = math.Abs(item.Amount)
			t.PositionEffect = item.PositionEffect
		}
		if t.Qty > 0 && t.PositionEffect != "" && strings.TrimSpace(t.Symbol) != "" {
			out = append(out, t)
		}
	}
	return out
}

func filterByEffect(trades []trade, effect string) []trade {
	var out []trade
	for _, t := range trades {
		if t.PositionEffect == effect {
			out = append(out, t)
		}
	}
	return out
}

func printTrades(trades []trade) {
	if len(trades) == 0 {
		fmt.Println("(none)")
		return
	}
	fmt.Printf("%-19s %-8s %-6s %-28s %12s %12s\n", "Time", "Range", "Effect", "Symbol", "Qty", "Net")
	fmt.Println(strings.Repeat("-", 93))
	for _, t := range trades {
		rangeVal := "history"
		if t.InRange {
			rangeVal = "target"
		}
		fmt.Printf("%-19s %-8s %-6s %-28s %12.4f %12.2f\n",
			t.Time.Local().Format("2006-01-02 15:04:05"),
			rangeVal,
			t.PositionEffect,
			truncate(t.Symbol, 28),
			t.Qty,
			t.NetAmount,
		)
	}
}

func truncate(s string, max int) string {
	if max <= 0 || len(s) <= max {
		return s
	}
	if max <= 3 {
		return s[:max]
	}
	return s[:max-3] + "..."
}
