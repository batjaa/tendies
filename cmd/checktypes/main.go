package main

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"time"

	"github.com/batjaa/tendies/internal/config"
	"github.com/batjaa/tendies/internal/schwab"
)

func main() {
	cfg, _ := config.Load()
	token, _ := config.LoadToken()
	client := schwab.NewClient(cfg.ClientID, cfg.ClientSecret, cfg.RedirectURL)
	if token.Expiry.Before(time.Now()) {
		newToken, err := client.RefreshToken(context.Background(), token)
		if err != nil {
			fmt.Fprintf(os.Stderr, "refresh failed: %v\n", err)
			os.Exit(1)
		}
		token = newToken
		config.SaveToken(token)
	}
	client.SetToken(token)

	ctx := context.Background()
	accounts, _ := client.GetAccountNumbers(ctx)
	acc := accounts[0]

	loc := time.Now().Location()
	from := time.Date(2026, 2, 4, 0, 0, 0, 0, loc)
	to := time.Date(2026, 2, 5, 0, 0, 0, 0, loc)

	raw, _ := client.GetTransactionsRaw(ctx, acc.HashValue, from, to, "TRADE")

	var txns []json.RawMessage
	json.Unmarshal(raw, &txns)

	for _, txn := range txns {
		s := string(txn)
		if strings.Contains(s, "META") || strings.Contains(s, "685") {
			var pretty []byte
			pretty, _ = json.MarshalIndent(txn, "", "  ")
			fmt.Println(string(pretty))
			fmt.Println()
		}
	}
}
