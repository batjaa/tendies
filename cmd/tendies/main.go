package main

import (
	"bufio"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/url"
	"os"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/batjaa/tendies/internal/broker"
	"github.com/batjaa/tendies/internal/config"
	"github.com/batjaa/tendies/internal/schwab"
	"github.com/spf13/cobra"
	"golang.org/x/oauth2"
)

// version is set at build time via ldflags.
var version = "dev"

const (
	colorReset          = "\033[0m"
	colorRed            = "\033[31m"
	colorGreen          = "\033[32m"
	displayNameCacheTTL = 7 * 24 * time.Hour
)

type timeframe struct {
	Name string
	From time.Time
	To   time.Time
}

type timeframeResult struct {
	Label   string
	Summary *schwab.PnLSummary
}

type cliOptions struct {
	showDay        bool
	showWeek       bool
	showMonth      bool
	showYear       bool
	debug          bool
	symbols        string
	refreshDetails bool
	accountID      string
	showCfg        bool
	direct         bool
}

func main() {
	opts := &cliOptions{}
	rootCmd := &cobra.Command{
		Use:   "tendies",
		Short: "Show realized trading P&L from Schwab",
		Long:  "Tendies shows realized gains/losses for day, week, month, and year.",
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) > 0 {
				return fmt.Errorf("unexpected arguments: %s", strings.Join(args, " "))
			}
			if opts.showCfg {
				return runConfig()
			}
			return runPnL(opts)
		},
	}
	loginCmd := &cobra.Command{
		Use:   "login",
		Short: "Authenticate and save OAuth token",
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) > 0 {
				return fmt.Errorf("unexpected arguments: %s", strings.Join(args, " "))
			}
			if opts.direct {
				return runDirectLogin()
			}
			return runBrokerLogin()
		},
	}
	accountsCmd := &cobra.Command{
		Use:   "accounts",
		Short: "List accessible Schwab accounts",
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) > 0 {
				return fmt.Errorf("unexpected arguments: %s", strings.Join(args, " "))
			}
			return runAccounts(opts)
		},
	}
	versionCmd := &cobra.Command{
		Use:   "version",
		Short: "Print the tendies version",
		Run: func(cmd *cobra.Command, args []string) {
			fmt.Println(version)
		},
	}
	rootCmd.AddCommand(loginCmd, accountsCmd, versionCmd)

	rootCmd.Flags().BoolVar(&opts.showDay, "day", false, "Show realized P&L for today")
	rootCmd.Flags().BoolVar(&opts.showWeek, "week", false, "Show realized P&L for this week")
	rootCmd.Flags().BoolVar(&opts.showMonth, "month", false, "Show realized P&L for this month")
	rootCmd.Flags().BoolVar(&opts.showYear, "year", false, "Show realized P&L for year-to-date")
	rootCmd.Flags().BoolVar(&opts.debug, "debug", false, "Show debug details during calculation")
	rootCmd.Flags().StringVar(&opts.symbols, "symbol", "", "Filter symbols/underlyings (comma-separated, e.g. HD,MU)")
	rootCmd.PersistentFlags().BoolVar(&opts.refreshDetails, "refresh-details", false, "Refresh cached account display details")
	rootCmd.Flags().StringVar(&opts.accountID, "account", "", "Account hash or account number")
	rootCmd.Flags().BoolVar(&opts.showCfg, "config", false, "Initialize/show configuration")
	rootCmd.PersistentFlags().BoolVar(&opts.direct, "direct", false, "Use direct Schwab API (requires own Schwab app credentials)")

	if err := rootCmd.Execute(); err != nil {
		fmt.Fprintf(os.Stderr, "Error: %v\n", err)
		os.Exit(1)
	}
}

func runConfig() error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}
	path, err := config.GetConfigPath()
	if err != nil {
		return err
	}

	if err := cfg.Save(); err != nil {
		return fmt.Errorf("failed to write config: %w", err)
	}

	fmt.Printf("Config file: %s\n", path)
	fmt.Printf("Client ID: %s\n", redacted(cfg.ClientID))
	fmt.Printf("Client Secret: %s\n", redacted(cfg.ClientSecret))
	fmt.Printf("Redirect URL: %s\n", cfg.RedirectURL)
	if len(cfg.Accounts) == 0 {
		fmt.Println("Accounts: (none set; will use all accessible accounts)")
	} else {
		fmt.Printf("Accounts: %s\n", strings.Join(cfg.Accounts, ", "))
	}
	fmt.Printf("Refresh (mins): %d\n", cfg.RefreshMins)
	fmt.Println("OAuth token is stored in system keychain.")

	return nil
}

func runPnL(opts *cliOptions) error {
	if err := validateTimeframeFlags(opts); err != nil {
		return err
	}

	cfg, err := config.Load()
	if err != nil {
		return err
	}

	ctx := context.Background()

	// Build data source: broker (default) or direct Schwab.
	var ds schwab.TransactionFetcher
	var client *schwab.Client // only set in direct mode (for display-name lookups)

	if opts.direct {
		if strings.TrimSpace(cfg.ClientID) == "" || strings.TrimSpace(cfg.ClientSecret) == "" {
			return errors.New("missing Schwab credentials in config; run tendies --config and set client_id/client_secret")
		}
		if strings.TrimSpace(cfg.RedirectURL) == "" {
			return errors.New("missing redirect_url in config; run tendies --config")
		}
		token, loadErr := config.LoadToken()
		if loadErr != nil {
			return loadErr
		}
		if token == nil {
			return errors.New("no OAuth token in keychain; run `tendies login --direct` first")
		}
		client = schwab.NewClient(cfg.ClientID, cfg.ClientSecret, cfg.RedirectURL)
		if err := runWithSpinner("Refreshing OAuth token", func() error {
			var refreshErr error
			token, refreshErr = ensureFreshToken(ctx, client, token)
			return refreshErr
		}); err != nil {
			return err
		}
		client.SetToken(token)
		ds = client
	} else {
		bc, bcErr := buildBrokerClient(cfg)
		if bcErr != nil {
			return bcErr
		}
		ds = bc
	}

	var accounts []schwab.AccountNumber
	if err := runWithSpinner("Loading accounts", func() error {
		var loadErr error
		accounts, loadErr = ds.GetAccountNumbers(ctx)
		return loadErr
	}); err != nil {
		return fmt.Errorf("failed to load accounts: %w", err)
	}

	displayByHash := make(map[string]string, len(accounts))
	cacheUpdated := false
	if client != nil {
		if err := runWithSpinner("Loading account display names", func() error {
			displayByHash, cacheUpdated = resolveAccountDisplayNames(ctx, client, cfg, accounts, opts.refreshDetails)
			return nil
		}); err != nil {
			return err
		}
	} else {
		for _, a := range accounts {
			displayByHash[a.HashValue] = accountFallbackLabel(a.AccountNumber)
		}
	}
	if cacheUpdated {
		if err := cfg.Save(); err != nil {
			fmt.Fprintf(os.Stderr, "Warning: failed to persist display-name cache: %v\n", err)
		}
	}

	symbolFilter := parseSymbolFilter(opts.symbols)
	selected, err := selectAccounts(accounts, cfg.Accounts, opts.accountID)
	if err != nil {
		return err
	}

	timeframes := selectedTimeframes(opts, time.Now())
	if opts.debug {
		fmt.Fprintf(os.Stderr, "Debug: timeframes=%d accounts=%d\n", len(timeframes), len(selected))
		if len(symbolFilter) > 0 {
			fmt.Fprintf(os.Stderr, "Debug: symbol-filter=%s\n", strings.Join(mapKeys(symbolFilter), ","))
		}
		for _, tf := range timeframes {
			fmt.Fprintf(os.Stderr, "Debug: timeframe=%s from=%s to=%s\n",
				tf.Name, tf.From.Format(time.RFC3339), tf.To.Format(time.RFC3339))
		}
	}

	results := make([]timeframeResult, 0, len(timeframes))
	var warnings []string
	for _, tf := range timeframes {
		total := &schwab.PnLSummary{}
		for _, accountHash := range selected {
			var s *schwab.PnLSummary
			accountLabel := displayByHash[accountHash]
			if accountLabel == "" {
				accountLabel = accountHash
			}
			label := fmt.Sprintf("Calculating %s P&L for %s", strings.ToLower(tf.Name), accountLabel)
			if err := runWithSpinner(label, func() error {
				var calcErr error
				s, calcErr = schwab.CalculateRealizedPnL(ctx, ds, accountHash, tf.From, tf.To)
				return calcErr
			}); err != nil {
				return fmt.Errorf("failed to calculate %s P&L for %s: %w", strings.ToLower(tf.Name), accountLabel, err)
			}
			if len(symbolFilter) > 0 {
				s = filterSummaryBySymbols(s, symbolFilter)
			}
			if opts.debug {
				fmt.Fprintf(os.Stderr, "Debug: timeframe=%s account=%s trades=%d gains=%s losses=%s net=%s\n",
					tf.Name, accountLabel, s.TradeCount, formatMoney(s.TotalGain), formatMoney(s.TotalLoss), formatMoney(s.NetGain))
				for _, w := range uniqueStrings(s.Warnings) {
					fmt.Fprintf(os.Stderr, "Debug: warning account=%s timeframe=%s: %s\n", accountLabel, tf.Name, w)
				}
				printDebugClosedTrades(os.Stderr, tf.Name, accountLabel, s.Trades)
			}
			total.TotalGain += s.TotalGain
			total.TotalLoss += s.TotalLoss
			total.NetGain += s.NetGain
			total.TradeCount += s.TradeCount
			total.Warnings = append(total.Warnings, s.Warnings...)
		}
		results = append(results, timeframeResult{Label: tf.Name, Summary: total})
		warnings = append(warnings, total.Warnings...)
	}

	selectedLabels := make([]string, 0, len(selected))
	for _, hash := range selected {
		label := displayByHash[hash]
		if label == "" {
			label = hash
		}
		selectedLabels = append(selectedLabels, label)
	}

	printSummary(results, selectedLabels, time.Now().Location())
	for _, w := range uniqueStrings(warnings) {
		fmt.Fprintf(os.Stderr, "Warning: %s\n", w)
	}
	return nil
}

func buildBrokerClient(cfg *config.Config) (*broker.Client, error) {
	brokerURL := cfg.BrokerURL
	if brokerURL == "" {
		return nil, errors.New("broker_url not set in config; run tendies --config or use --direct for direct Schwab API access")
	}
	clientID := cfg.BrokerClientID
	if clientID == "" {
		return nil, errors.New("broker_client_id not set in config")
	}

	bt, err := config.LoadBrokerToken()
	if err != nil {
		return nil, err
	}
	if bt == nil {
		return nil, errors.New("no broker token in keychain; run `tendies login` first")
	}

	bc := broker.NewClient(brokerURL, clientID)
	bc.AccessToken = bt.AccessToken
	bc.RefreshToken = bt.RefreshToken
	bc.TokenExpiry = bt.Expiry
	return bc, nil
}

func printDebugClosedTrades(w io.Writer, timeframeName, accountLabel string, trades []schwab.ClosedTrade) {
	fmt.Fprintf(w, "Debug: closed-trades timeframe=%s account=%s count=%d\n", timeframeName, accountLabel, len(trades))
	if len(trades) == 0 {
		return
	}
	fmt.Fprintln(w, "Debug: ActivityID    CloseTime              Symbol                         Qty        OpenCash      CloseCash      PnL")
	fmt.Fprintln(w, "Debug: ----------------------------------------------------------------------------------------------------------------")
	for _, t := range trades {
		fmt.Fprintf(w, "Debug: %-13d %-21s %-30s %8.4f %13.2f %13.2f %10.2f\n",
			t.ActivityID,
			t.CloseTime.Local().Format("2006-01-02 15:04:05"),
			truncate(t.Symbol, 30),
			t.Quantity,
			t.OpenCash,
			t.CloseCash,
			t.RealizedPnL,
		)
		if len(t.MatchedOpenings) > 0 {
			fmt.Fprintln(w, "Debug:   matched-openings:")
			fmt.Fprintln(w, "Debug:   OpenTime               OpenActivity    Qty        OpenCash      OpenPU")
			for _, m := range t.MatchedOpenings {
				fmt.Fprintf(w, "Debug:   %-21s %-14d %8.4f %13.2f %11.4f\n",
					m.OpenTime.Local().Format("2006-01-02 15:04:05"),
					m.OpenActivityID,
					m.Quantity,
					m.OpenCash,
					m.OpenCashPerUnit,
				)
			}
		}
	}
}

func parseSymbolFilter(raw string) map[string]struct{} {
	out := map[string]struct{}{}
	for _, part := range strings.Split(raw, ",") {
		s := strings.ToUpper(strings.TrimSpace(part))
		if s == "" {
			continue
		}
		out[s] = struct{}{}
	}
	return out
}

func symbolMatchesFilter(symbol string, filter map[string]struct{}) bool {
	if len(filter) == 0 {
		return true
	}
	full := strings.ToUpper(strings.TrimSpace(symbol))
	if _, ok := filter[full]; ok {
		return true
	}
	root := full
	if fields := strings.Fields(full); len(fields) > 0 {
		root = fields[0]
	}
	_, ok := filter[root]
	return ok
}

func filterSummaryBySymbols(summary *schwab.PnLSummary, filter map[string]struct{}) *schwab.PnLSummary {
	if summary == nil || len(filter) == 0 {
		return summary
	}
	filtered := &schwab.PnLSummary{
		Warnings: append([]string(nil), summary.Warnings...),
	}
	for _, t := range summary.Trades {
		if !symbolMatchesFilter(t.Symbol, filter) {
			continue
		}
		filtered.Trades = append(filtered.Trades, t)
		filtered.TradeCount++
		if t.RealizedPnL >= 0 {
			filtered.TotalGain += t.RealizedPnL
		} else {
			filtered.TotalLoss += t.RealizedPnL
		}
	}
	filtered.NetGain = filtered.TotalGain + filtered.TotalLoss
	return filtered
}

func mapKeys(m map[string]struct{}) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

func runBrokerLogin() error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}
	brokerURL := cfg.BrokerURL
	if brokerURL == "" {
		return errors.New("broker_url not set in config; run tendies --config or use --direct for direct Schwab API access")
	}
	clientID := cfg.BrokerClientID
	if clientID == "" {
		return errors.New("broker_client_id not set in config")
	}

	bc := broker.NewClient(brokerURL, clientID)
	ctx := context.Background()

	if err := bc.Login(ctx); err != nil {
		return fmt.Errorf("broker login failed: %w", err)
	}

	if err := config.SaveBrokerToken(&config.BrokerToken{
		AccessToken:  bc.AccessToken,
		RefreshToken: bc.RefreshToken,
		Expiry:       bc.TokenExpiry,
	}); err != nil {
		return err
	}

	fmt.Printf("Login complete. Token saved to keychain (expires: %s).\n", bc.TokenExpiry.Local().Format(time.RFC3339))
	return nil
}

func runDirectLogin() error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}
	if strings.TrimSpace(cfg.ClientID) == "" || strings.TrimSpace(cfg.ClientSecret) == "" {
		return errors.New("missing Schwab credentials in config; run tendies --config and set client_id/client_secret")
	}
	if strings.TrimSpace(cfg.RedirectURL) == "" {
		return errors.New("missing redirect_url in config; run tendies --config")
	}

	client := schwab.NewClient(cfg.ClientID, cfg.ClientSecret, cfg.RedirectURL)
	state, err := randomState()
	if err != nil {
		return fmt.Errorf("failed to generate OAuth state: %w", err)
	}

	authURL := client.GetAuthURL(state)
	fmt.Println("Open this URL in your browser and authorize access:")
	fmt.Println(authURL)
	fmt.Println()
	fmt.Print("Paste the redirect URL (or just the `code` value): ")

	line, err := bufio.NewReader(os.Stdin).ReadString('\n')
	if err != nil && !errors.Is(err, io.EOF) {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" {
			return fmt.Errorf("failed to read authorization input: %w", err)
		}
	}

	code, returnedState, err := parseOAuthInput(line)
	if err != nil {
		return err
	}
	if returnedState != "" && returnedState != state {
		return errors.New("state mismatch in callback URL")
	}

	token, err := client.ExchangeCode(context.Background(), code)
	if err != nil {
		return fmt.Errorf("failed to exchange authorization code: %w", err)
	}
	if err := config.SaveToken(token); err != nil {
		return err
	}

	fmt.Printf("Login complete. Token saved to keychain (expires: %s).\n", token.Expiry.Local().Format(time.RFC3339))
	return nil
}

func runAccounts(opts *cliOptions) error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}

	ctx := context.Background()

	// Build data source
	var ds schwab.TransactionFetcher
	var client *schwab.Client

	if opts.direct {
		if strings.TrimSpace(cfg.ClientID) == "" || strings.TrimSpace(cfg.ClientSecret) == "" {
			return errors.New("missing Schwab credentials in config; run tendies --config and set client_id/client_secret")
		}
		if strings.TrimSpace(cfg.RedirectURL) == "" {
			return errors.New("missing redirect_url in config; run tendies --config")
		}
		token, loadErr := config.LoadToken()
		if loadErr != nil {
			return loadErr
		}
		if token == nil {
			return errors.New("no OAuth token in keychain; run `tendies login --direct` first")
		}
		client = schwab.NewClient(cfg.ClientID, cfg.ClientSecret, cfg.RedirectURL)
		if err := runWithSpinner("Refreshing OAuth token", func() error {
			var refreshErr error
			token, refreshErr = ensureFreshToken(ctx, client, token)
			return refreshErr
		}); err != nil {
			return err
		}
		client.SetToken(token)
		ds = client
	} else {
		bc, bcErr := buildBrokerClient(cfg)
		if bcErr != nil {
			return bcErr
		}
		ds = bc
	}

	var accounts []schwab.AccountNumber
	if err := runWithSpinner("Loading accounts", func() error {
		var loadErr error
		accounts, loadErr = ds.GetAccountNumbers(ctx)
		return loadErr
	}); err != nil {
		return fmt.Errorf("failed to load accounts: %w", err)
	}
	if len(accounts) == 0 {
		fmt.Println("No accounts returned.")
		return nil
	}

	selected := map[string]struct{}{}
	if len(cfg.Accounts) == 0 {
		for _, a := range accounts {
			selected[a.HashValue] = struct{}{}
		}
	} else {
		ids, selectErr := selectAccounts(accounts, cfg.Accounts, "")
		if selectErr != nil {
			fmt.Fprintf(os.Stderr, "Warning: configured account selection is invalid: %v\n", selectErr)
		} else {
			for _, id := range ids {
				selected[id] = struct{}{}
			}
		}
	}

	fmt.Println("Available accounts:")
	fmt.Printf("%-10s %-40s %-24s %-8s\n", "Number", "Hash", "Name", "Selected")
	fmt.Println(strings.Repeat("-", 87))

	nameByHash := make(map[string]string, len(accounts))
	cacheUpdated := false
	if client != nil {
		if err := runWithSpinner("Loading account friendly names", func() error {
			nameByHash, cacheUpdated = resolveAccountDisplayNames(ctx, client, cfg, accounts, opts.refreshDetails)
			return nil
		}); err != nil {
			return err
		}
	} else {
		for _, a := range accounts {
			nameByHash[a.HashValue] = accountFallbackLabel(a.AccountNumber)
		}
	}
	if cacheUpdated {
		if err := cfg.Save(); err != nil {
			fmt.Fprintf(os.Stderr, "Warning: failed to persist display-name cache: %v\n", err)
		}
	}

	for _, a := range accounts {
		_, ok := selected[a.HashValue]
		flag := "no"
		if ok {
			flag = "yes"
		}
		fmt.Printf("%-10s %-40s %-24s %-8s\n", a.AccountNumber, a.HashValue, truncate(nameByHash[a.HashValue], 24), flag)
	}
	return nil
}

func runWithSpinner(label string, fn func() error) error {
	sp := startSpinner(label)
	err := fn()
	sp.stop(err == nil)
	return err
}

type spinner struct {
	label  string
	done   chan struct{}
	once   sync.Once
	ticker *time.Ticker
}

func startSpinner(label string) *spinner {
	s := &spinner{
		label:  label,
		done:   make(chan struct{}),
		ticker: time.NewTicker(120 * time.Millisecond),
	}
	go s.loop()
	return s
}

func (s *spinner) loop() {
	frames := []string{"|", "/", "-", "\\"}
	i := 0
	for {
		select {
		case <-s.done:
			return
		case <-s.ticker.C:
			fmt.Fprintf(os.Stderr, "\r%s %s", frames[i%len(frames)], s.label)
			i++
		}
	}
}

func (s *spinner) stop(ok bool) {
	s.once.Do(func() {
		close(s.done)
		s.ticker.Stop()
		status := "done"
		if !ok {
			status = "failed"
		}
		fmt.Fprintf(os.Stderr, "\r%s... %s\n", s.label, status)
	})
}

func extractFriendlyName(raw []byte) string {
	var obj map[string]any
	if err := json.Unmarshal(raw, &obj); err != nil {
		return ""
	}
	keys := []string{
		"displayName",
		"nickname",
		"nickName",
		"accountName",
		"name",
	}
	for _, key := range keys {
		if v := findStringField(obj, key); v != "" {
			return strings.TrimSpace(v)
		}
	}
	return ""
}

func buildAccountDisplayNames(ctx context.Context, client *schwab.Client, accounts []schwab.AccountNumber) map[string]string {
	nameByHash := make(map[string]string, len(accounts))
	for _, a := range accounts {
		label := accountFallbackLabel(a.AccountNumber)
		raw, detailsErr := client.GetAccountDetailsRaw(ctx, a.HashValue)
		if detailsErr == nil {
			if friendly := extractFriendlyName(raw); friendly != "" {
				label = friendly
			}
		}
		nameByHash[a.HashValue] = label
	}
	return nameByHash
}

func resolveAccountDisplayNames(ctx context.Context, client *schwab.Client, cfg *config.Config, accounts []schwab.AccountNumber, forceRefresh bool) (map[string]string, bool) {
	if cfg.DisplayNameCache == nil {
		cfg.DisplayNameCache = make(map[string]config.DisplayNameCacheEntry)
	}
	now := time.Now().UTC()
	updated := false
	nameByHash := make(map[string]string, len(accounts))

	for _, a := range accounts {
		fallback := accountFallbackLabel(a.AccountNumber)
		entry, hasEntry := cfg.DisplayNameCache[a.HashValue]
		if !forceRefresh && hasEntry && cacheEntryFresh(entry.UpdatedAt, now) && strings.TrimSpace(entry.Name) != "" {
			nameByHash[a.HashValue] = entry.Name
			continue
		}

		label := ""
		raw, detailsErr := client.GetAccountDetailsRaw(ctx, a.HashValue)
		if detailsErr == nil {
			label = strings.TrimSpace(extractFriendlyName(raw))
		}

		if label == "" {
			if strings.TrimSpace(entry.Name) != "" {
				label = strings.TrimSpace(entry.Name)
			} else {
				label = fallback
			}
		}
		nameByHash[a.HashValue] = label

		newEntry := config.DisplayNameCacheEntry{
			Name:      label,
			UpdatedAt: now.Format(time.RFC3339),
		}
		if !hasEntry || entry.Name != newEntry.Name || entry.UpdatedAt != newEntry.UpdatedAt {
			cfg.DisplayNameCache[a.HashValue] = newEntry
			updated = true
		}
	}

	return nameByHash, updated
}

func cacheEntryFresh(updatedAt string, now time.Time) bool {
	updatedAt = strings.TrimSpace(updatedAt)
	if updatedAt == "" {
		return false
	}
	ts, err := time.Parse(time.RFC3339, updatedAt)
	if err != nil {
		return false
	}
	age := now.Sub(ts)
	return age >= 0 && age <= displayNameCacheTTL
}

func accountFallbackLabel(accountNumber string) string {
	accountNumber = strings.TrimSpace(accountNumber)
	if accountNumber == "" {
		return "...???"
	}
	if len(accountNumber) <= 3 {
		return "..." + accountNumber
	}
	return "..." + accountNumber[len(accountNumber)-3:]
}

func findStringField(v any, key string) string {
	switch val := v.(type) {
	case map[string]any:
		for k, vv := range val {
			if strings.EqualFold(k, key) {
				if s, ok := vv.(string); ok && strings.TrimSpace(s) != "" {
					return s
				}
			}
			if s := findStringField(vv, key); s != "" {
				return s
			}
		}
	case []any:
		for _, item := range val {
			if s := findStringField(item, key); s != "" {
				return s
			}
		}
	}
	return ""
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

func uniqueStrings(items []string) []string {
	seen := make(map[string]struct{}, len(items))
	out := make([]string, 0, len(items))
	for _, item := range items {
		item = strings.TrimSpace(item)
		if item == "" {
			continue
		}
		if _, ok := seen[item]; ok {
			continue
		}
		seen[item] = struct{}{}
		out = append(out, item)
	}
	return out
}

func randomState() (string, error) {
	b := make([]byte, 16)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return hex.EncodeToString(b), nil
}

func parseOAuthInput(input string) (code string, state string, err error) {
	input = strings.TrimSpace(input)
	if input == "" {
		return "", "", errors.New("no authorization input provided")
	}
	if !strings.Contains(input, "=") {
		return input, "", nil
	}

	query := input
	switch {
	case strings.Contains(input, "://"):
		u, parseErr := url.Parse(input)
		if parseErr != nil {
			return "", "", fmt.Errorf("failed to parse callback URL: %w", parseErr)
		}
		query = u.RawQuery
	case strings.HasPrefix(input, "?"):
		query = strings.TrimPrefix(input, "?")
	case strings.Contains(input, "?"):
		query = strings.SplitN(input, "?", 2)[1]
	}

	values, parseErr := url.ParseQuery(query)
	if parseErr != nil {
		return "", "", fmt.Errorf("failed to parse callback query: %w", parseErr)
	}
	code = values.Get("code")
	if code == "" {
		return "", "", errors.New("missing `code` in callback input")
	}
	return code, values.Get("state"), nil
}

func validateTimeframeFlags(opts *cliOptions) error {
	count := 0
	if opts.showDay {
		count++
	}
	if opts.showWeek {
		count++
	}
	if opts.showMonth {
		count++
	}
	if opts.showYear {
		count++
	}
	if count > 1 {
		return errors.New("use only one timeframe flag at a time (--day, --week, --month, or --year)")
	}
	return nil
}

func ensureFreshToken(ctx context.Context, client *schwab.Client, token *oauth2.Token) (*oauth2.Token, error) {
	if token.Valid() {
		return token, nil
	}
	newToken, err := client.RefreshToken(ctx, token)
	if err != nil {
		return nil, fmt.Errorf("failed to refresh OAuth token: %w", err)
	}
	if err := config.SaveToken(newToken); err != nil {
		return nil, fmt.Errorf("failed to persist refreshed token: %w", err)
	}
	return newToken, nil
}

func selectedTimeframes(opts *cliOptions, now time.Time) []timeframe {
	loc := now.Location()
	todayStart := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, loc)

	mondayOffset := (int(now.Weekday()) + 6) % 7 // Monday=0, Sunday=6
	weekStart := todayStart.AddDate(0, 0, -mondayOffset)
	monthStart := time.Date(now.Year(), now.Month(), 1, 0, 0, 0, 0, loc)
	yearStart := time.Date(now.Year(), time.January, 1, 0, 0, 0, 0, loc)

	if opts.showDay {
		return []timeframe{{Name: "Day", From: todayStart, To: now}}
	}
	if opts.showWeek {
		return []timeframe{{Name: "Week", From: weekStart, To: now}}
	}
	if opts.showMonth {
		return []timeframe{{Name: "Month", From: monthStart, To: now}}
	}
	if opts.showYear {
		return []timeframe{{Name: "Year", From: yearStart, To: now}}
	}

	return []timeframe{
		{Name: "Day", From: todayStart, To: now},
		{Name: "Week", From: weekStart, To: now},
		{Name: "Month", From: monthStart, To: now},
		{Name: "Year", From: yearStart, To: now},
	}
}

func selectAccounts(accounts []schwab.AccountNumber, configAccounts []string, override string) ([]string, error) {
	byHash := make(map[string]schwab.AccountNumber, len(accounts))
	byNumber := make(map[string]schwab.AccountNumber, len(accounts))
	for _, a := range accounts {
		byHash[a.HashValue] = a
		byNumber[a.AccountNumber] = a
	}

	resolve := func(id string) (string, error) {
		id = strings.TrimSpace(id)
		if id == "" {
			return "", errors.New("empty account id")
		}
		if acct, ok := byHash[id]; ok {
			return acct.HashValue, nil
		}
		if acct, ok := byNumber[id]; ok {
			return acct.HashValue, nil
		}
		return "", fmt.Errorf("account not found: %s", id)
	}

	var ids []string
	switch {
	case strings.TrimSpace(override) != "":
		resolved, err := resolve(override)
		if err != nil {
			return nil, err
		}
		ids = append(ids, resolved)
	case len(configAccounts) > 0:
		for _, id := range configAccounts {
			resolved, err := resolve(id)
			if err != nil {
				return nil, fmt.Errorf("invalid configured account %q: %w", id, err)
			}
			ids = append(ids, resolved)
		}
	default:
		for _, a := range accounts {
			ids = append(ids, a.HashValue)
		}
	}

	if len(ids) == 0 {
		return nil, errors.New("no accounts selected")
	}

	uniq := make(map[string]struct{}, len(ids))
	out := make([]string, 0, len(ids))
	for _, id := range ids {
		if _, ok := uniq[id]; ok {
			continue
		}
		uniq[id] = struct{}{}
		out = append(out, id)
	}
	sort.Strings(out)
	return out, nil
}

func printSummary(results []timeframeResult, accounts []string, loc *time.Location) {
	fmt.Printf("Tendies Realized P&L (%s)\n", time.Now().In(loc).Format("2006-01-02 15:04:05 MST"))
	if len(accounts) == 1 {
		fmt.Printf("Account: %s\n", accounts[0])
	} else {
		fmt.Printf("Accounts: %s\n", strings.Join(accounts, ", "))
	}
	fmt.Println()
	fmt.Printf("%-8s %14s %14s %14s %8s\n", "Period", "Gains", "Losses", "Net", "Trades")
	fmt.Println(strings.Repeat("-", 66))
	for _, r := range results {
		net := colorizeMoney(r.Summary.NetGain)
		fmt.Printf("%-8s %14s %14s %14s %8d\n",
			r.Label,
			formatMoney(r.Summary.TotalGain),
			formatMoney(r.Summary.TotalLoss),
			net,
			r.Summary.TradeCount,
		)
	}
}

func formatMoney(v float64) string {
	sign := ""
	if v < 0 {
		sign = "-"
		v = -v
	}
	return fmt.Sprintf("%s$%0.2f", sign, v)
}

func colorizeMoney(v float64) string {
	formatted := formatMoney(v)
	if v > 0 {
		return colorGreen + formatted + colorReset
	}
	if v < 0 {
		return colorRed + formatted + colorReset
	}
	return formatted
}

func redacted(s string) string {
	s = strings.TrimSpace(s)
	if s == "" {
		return "(not set)"
	}
	if len(s) <= 6 {
		return "******"
	}
	return s[:3] + strings.Repeat("*", len(s)-6) + s[len(s)-3:]
}
