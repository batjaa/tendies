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
	"math"
	"os"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/batjaa/tendies/internal/broker"
	"github.com/batjaa/tendies/internal/config"
	"github.com/batjaa/tendies/internal/schwab"
	"github.com/spf13/cobra"
	"golang.org/x/oauth2"
	"golang.org/x/term"
)

// version is set at build time via ldflags.
var version = "dev"

// patTokenTTL is the lifetime of personal access tokens from register/login.
const patTokenTTL = 90 * 24 * time.Hour

const (
	colorReset          = "\033[0m"
	colorRed            = "\033[31m"
	colorGreen          = "\033[32m"
	colorDim            = "\033[2m"
	colorBold           = "\033[1m"
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
	jsonOutput     bool
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
			err := runPnL(opts)
			if err != nil && opts.jsonOutput {
				writeJSONError(err)
				os.Exit(1)
			}
			return err
		},
	}
	accountCmd := &cobra.Command{
		Use:     "account",
		Aliases: []string{"accounts"},
		Short:   "Manage Schwab accounts",
	}
	accountListCmd := &cobra.Command{
		Use:   "list",
		Short: "List accessible Schwab accounts",
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) > 0 {
				return fmt.Errorf("unexpected arguments: %s", strings.Join(args, " "))
			}
			return runAccounts(opts)
		},
	}
	accountLinkCmd := &cobra.Command{
		Use:   "link",
		Short: "Link a new Schwab account",
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) > 0 {
				return fmt.Errorf("unexpected arguments: %s", strings.Join(args, " "))
			}
			return runAccountLink()
		},
	}
	accountCreateCmd := &cobra.Command{
		Use:   "create",
		Short: "Create a new account with email and password",
		RunE: func(cmd *cobra.Command, args []string) error {
			return runAccountCreate()
		},
	}
	accountLoginCmd := &cobra.Command{
		Use:   "login",
		Short: "Log in with email and password",
		RunE: func(cmd *cobra.Command, args []string) error {
			return runAccountLogin()
		},
	}
	accountLogoutCmd := &cobra.Command{
		Use:   "logout",
		Short: "Remove saved token from keychain",
		RunE: func(cmd *cobra.Command, args []string) error {
			return runAccountLogout(opts)
		},
	}
	accountStatusCmd := &cobra.Command{
		Use:   "status",
		Short: "Show account info and subscription status",
		RunE: func(cmd *cobra.Command, args []string) error {
			return runAccountStatus()
		},
	}
	accountCmd.AddCommand(accountListCmd, accountLinkCmd, accountCreateCmd, accountLoginCmd, accountLogoutCmd, accountStatusCmd)
	versionCmd := &cobra.Command{
		Use:   "version",
		Short: "Print the tendies version",
		Run: func(cmd *cobra.Command, args []string) {
			fmt.Println(version)
		},
	}
	rootCmd.AddCommand(accountCmd, versionCmd)

	rootCmd.Flags().BoolVar(&opts.showDay, "day", false, "Show realized P&L for today")
	rootCmd.Flags().BoolVar(&opts.showWeek, "week", false, "Show realized P&L for this week")
	rootCmd.Flags().BoolVar(&opts.showMonth, "month", false, "Show realized P&L for this month")
	rootCmd.Flags().BoolVar(&opts.showYear, "year", false, "Show realized P&L for year-to-date")
	rootCmd.Flags().BoolVar(&opts.debug, "debug", false, "Show debug details during calculation")
	rootCmd.Flags().StringVar(&opts.symbols, "symbol", "", "Filter symbols/underlyings (comma-separated, e.g. HD,MU)")
	rootCmd.PersistentFlags().BoolVar(&opts.refreshDetails, "refresh-details", false, "Refresh cached account display details")
	rootCmd.Flags().StringVar(&opts.accountID, "account", "", "Account hash or account number")
	rootCmd.Flags().BoolVar(&opts.showCfg, "config", false, "Initialize/show configuration")
	rootCmd.Flags().BoolVar(&opts.jsonOutput, "json", false, "Output structured JSON to stdout (for menu bar app)")
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
	if !opts.jsonOutput {
		if err := validateTimeframeFlags(opts); err != nil {
			return err
		}
	}

	cfg, err := config.Load()
	if err != nil {
		return err
	}

	ctx := context.Background()

	// In JSON mode, suppress spinners and stderr output.
	run := runWithSpinner
	if opts.jsonOutput {
		run = func(_ string, fn func() error) error { return fn() }
	}

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
			return errors.New("no OAuth token in keychain; run `tendies auth login --direct` first")
		}
		client = schwab.NewClient(cfg.ClientID, cfg.ClientSecret, cfg.RedirectURL)
		if err := run("Refreshing OAuth token", func() error {
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
	if err := run("Loading accounts", func() error {
		var loadErr error
		accounts, loadErr = ds.GetAccountNumbers(ctx)
		return loadErr
	}); err != nil {
		return fmt.Errorf("failed to load accounts: %w", err)
	}

	displayByHash := make(map[string]string, len(accounts))
	cacheUpdated := false
	if client != nil {
		if err := run("Loading account display names", func() error {
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
			if !opts.jsonOutput {
				fmt.Fprintf(os.Stderr, "Warning: failed to persist display-name cache: %v\n", err)
			}
		}
	}

	symbolFilter := parseSymbolFilter(opts.symbols)
	selected, err := selectAccounts(accounts, cfg.Accounts, opts.accountID)
	if err != nil {
		return err
	}

	timeframes := selectedTimeframes(opts, time.Now())
	if opts.jsonOutput {
		// Exclude Year from JSON output.
		filtered := make([]timeframe, 0, len(timeframes))
		for _, tf := range timeframes {
			if tf.Name != "Year" {
				filtered = append(filtered, tf)
			}
		}
		timeframes = filtered
	}

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
			if err := run(label, func() error {
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
			total.Trades = append(total.Trades, s.Trades...)
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

	if opts.jsonOutput {
		return outputJSON(results, selectedLabels, selected, warnings, time.Now())
	}

	printSummary(results, selectedLabels, time.Now().Location())
	if len(symbolFilter) > 0 || opts.showDay || opts.showWeek {
		printTrades(results)
	}
	for _, w := range uniqueStrings(warnings) {
		fmt.Fprintf(os.Stderr, "Warning: %s\n", w)
	}
	return nil
}

func buildBrokerClient(cfg *config.Config) (*broker.Client, error) {
	clientID := cfg.BrokerClientID
	if clientID == "" {
		return nil, errors.New("broker_client_id not set in config; run tendies --config or use --direct for direct Schwab API access")
	}

	bt, err := config.LoadBrokerToken()
	if err != nil {
		return nil, err
	}
	if bt == nil {
		return nil, errors.New("no broker token in keychain; run `tendies auth login` first")
	}

	bc := broker.NewClient(cfg.BrokerURL, clientID)
	bc.AccessToken = bt.AccessToken
	bc.RefreshToken = bt.RefreshToken
	bc.TokenExpiry = bt.Expiry

	// Set rate-limit headers for free-tier tracking.
	if qid, err := randomState(); err == nil {
		bc.QueryID = qid
	}
	bc.Timezone = time.Now().Location().String()

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

func runAccountCreate() error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}

	name, err := promptLine("Name: ")
	if err != nil {
		return err
	}
	email, err := promptLine("Email: ")
	if err != nil {
		return err
	}
	pw, err := promptPassword("Password: ")
	if err != nil {
		return err
	}

	bc := broker.NewClient(cfg.BrokerURL, cfg.BrokerClientID)
	ctx := context.Background()

	// If there's an existing token (from account link), upgrade the anonymous
	// account instead of creating a new one. This preserves linked trading accounts.
	var resp *broker.AuthResponse
	existing, _ := config.LoadBrokerToken()
	if existing != nil && existing.AccessToken != "" {
		bc.AccessToken = existing.AccessToken
		resp, err = bc.Upgrade(ctx, name, email, pw)
	} else {
		resp, err = bc.Register(ctx, name, email, pw)
	}
	if err != nil {
		return err
	}

	if err := config.SaveBrokerToken(&config.BrokerToken{
		AccessToken: resp.Token,
		Expiry:      time.Now().Add(patTokenTTL),
	}); err != nil {
		return err
	}

	fmt.Printf("Account created. Welcome, %s! (tier: %s)\n", resp.User.Name, resp.User.Tier)
	if existing == nil || existing.AccessToken == "" {
		fmt.Println("Run `tendies account link` to connect your Schwab account.")
	}
	return nil
}

func runAccountLogin() error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}

	email, err := promptLine("Email: ")
	if err != nil {
		return err
	}
	pw, err := promptPassword("Password: ")
	if err != nil {
		return err
	}

	bc := broker.NewClient(cfg.BrokerURL, cfg.BrokerClientID)
	ctx := context.Background()

	resp, err := bc.AuthLogin(ctx, email, pw)
	if err != nil {
		return err
	}

	if err := config.SaveBrokerToken(&config.BrokerToken{
		AccessToken: resp.Token,
		Expiry:      time.Now().Add(patTokenTTL),
	}); err != nil {
		return err
	}

	fmt.Printf("Logged in as %s (%s, tier: %s)\n", resp.User.Name, resp.User.Email, resp.User.Tier)
	return nil
}

func runAccountLogout(opts *cliOptions) error {
	if opts.direct {
		if err := config.DeleteToken(); err != nil {
			return fmt.Errorf("failed to delete token: %w", err)
		}
		fmt.Println("Logged out (direct mode token removed).")
	} else {
		if err := config.DeleteBrokerToken(); err != nil {
			return fmt.Errorf("failed to delete token: %w", err)
		}
		fmt.Println("Logged out (broker token removed).")
	}
	return nil
}

func runAccountStatus() error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}

	bt, _ := config.LoadBrokerToken()
	if bt == nil || bt.AccessToken == "" {
		fmt.Println("Not logged in.")
		fmt.Println()
		fmt.Println("  tendies account link     Connect a Schwab account (anonymous)")
		fmt.Println("  tendies account create   Create an account with email/password")
		fmt.Println("  tendies account login    Log in to an existing account")
		return nil
	}

	bc, err := buildBrokerClient(cfg)
	if err != nil {
		return err
	}

	ctx := context.Background()
	status, err := bc.GetStatus(ctx)
	if err != nil {
		return fmt.Errorf("failed to get account status: %w", err)
	}

	u := status.User
	fmt.Printf("Name:     %s\n", u.Name)
	if u.Email != "" {
		fmt.Printf("Email:    %s\n", u.Email)
	} else {
		fmt.Printf("Email:    %s(anonymous)%s\n", colorDim, colorReset)
	}
	fmt.Printf("Tier:     %s\n", u.Tier)
	fmt.Printf("Accounts: %d linked\n", status.LinkedAccounts)

	if status.Subscription != nil {
		fmt.Printf("Plan:     %s (%s)\n", status.Subscription.Plan, status.Subscription.Status)
	}
	if status.TrialEndsAt != nil {
		if t, err := time.Parse(time.RFC3339, *status.TrialEndsAt); err == nil {
			remaining := time.Until(t)
			if remaining > 0 {
				fmt.Printf("Trial:    %d days remaining\n", int(remaining.Hours()/24))
			} else {
				fmt.Printf("Trial:    expired\n")
			}
		}
	}

	return nil
}

// stdinReader is shared across all prompt functions to avoid buffer conflicts.
var stdinReader = bufio.NewReader(os.Stdin)

func promptLine(label string) (string, error) {
	fmt.Print(label)
	line, err := stdinReader.ReadString('\n')
	if err != nil && len(strings.TrimSpace(line)) == 0 {
		return "", err
	}
	return strings.TrimSpace(line), nil
}

func promptPassword(label string) (string, error) {
	fmt.Print(label)
	fd := int(os.Stdin.Fd())
	if term.IsTerminal(fd) {
		pw, err := term.ReadPassword(fd)
		fmt.Println()
		if err != nil {
			return "", err
		}
		return string(pw), nil
	}
	// Fallback for non-TTY (piped input, CI).
	line, err := stdinReader.ReadString('\n')
	if err != nil && len(strings.TrimSpace(line)) == 0 {
		return "", err
	}
	return strings.TrimSpace(line), nil
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
			return errors.New("no OAuth token in keychain; run `tendies auth login --direct` first")
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
	fmt.Printf("%s%-10s %-40s %-24s %-8s%s\n", colorDim, "Number", "Hash", "Name", "Selected", colorReset)
	fmt.Printf("%s%s%s\n", colorDim, strings.Repeat("─", 87), colorReset)

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

func runAccountLink() error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}

	// Check for existing token — determines which flow to use.
	bt, _ := config.LoadBrokerToken()

	if bt != nil {
		// Authenticated flow: initiate link session via API.
		return runAuthenticatedLink(cfg, bt)
	}

	// No token: anonymous PKCE flow (first-time user).
	return runAnonymousLink(cfg)
}

// runAuthenticatedLink links a new Schwab account for an already-authenticated user.
func runAuthenticatedLink(cfg *config.Config, bt *config.BrokerToken) error {
	bc := broker.NewClient(cfg.BrokerURL, cfg.BrokerClientID)
	bc.AccessToken = bt.AccessToken
	bc.RefreshToken = bt.RefreshToken
	bc.TokenExpiry = bt.Expiry

	ctx := context.Background()

	fmt.Println("Requesting link session...")
	authorizeURL, err := bc.InitiateLink(ctx, "schwab")
	if err != nil {
		var limErr *broker.AccountLimitError
		if errors.As(err, &limErr) {
			return fmt.Errorf("cannot link: %s", limErr.Message)
		}
		return fmt.Errorf("failed to initiate link: %w", err)
	}

	fmt.Println("Opening browser for Schwab authorization...")
	fmt.Printf("If the browser doesn't open, visit:\n%s\n\n", authorizeURL)
	broker.OpenBrowser(authorizeURL)

	fmt.Print("Press Enter after completing authorization in your browser...")
	stdinReader.ReadString('\n')

	// Verify by fetching accounts.
	var accounts []schwab.AccountNumber
	if err := runWithSpinner("Verifying linked accounts", func() error {
		var loadErr error
		accounts, loadErr = bc.GetAccountNumbers(ctx)
		return loadErr
	}); err != nil {
		return fmt.Errorf("failed to verify accounts: %w", err)
	}

	fmt.Printf("Account linked successfully. You have %d Schwab account(s).\n", len(accounts))
	return nil
}

// runAnonymousLink performs PKCE OAuth for first-time users with no token.
func runAnonymousLink(cfg *config.Config) error {
	clientID := cfg.BrokerClientID
	if clientID == "" {
		return errors.New("broker_client_id not set in config; run tendies --config")
	}

	bc := broker.NewClient(cfg.BrokerURL, clientID)
	ctx := context.Background()

	fmt.Println("Opening browser for Schwab authorization...")
	if err := bc.Login(ctx); err != nil {
		return fmt.Errorf("schwab authorization failed: %w", err)
	}

	if err := config.SaveBrokerToken(&config.BrokerToken{
		AccessToken:  bc.AccessToken,
		RefreshToken: bc.RefreshToken,
		Expiry:       bc.TokenExpiry,
	}); err != nil {
		return err
	}

	fmt.Printf("Account linked and logged in (token expires: %s).\n", bc.TokenExpiry.Local().Format(time.RFC3339))
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
	frames := []string{"⠋", "⠙", "⠹", "⠸", "⠼", "⠴", "⠦", "⠧", "⠇", "⠏"}
	i := 0
	for {
		select {
		case <-s.done:
			return
		case <-s.ticker.C:
			fmt.Fprintf(os.Stderr, "\r%s%s%s %s", colorDim, frames[i%len(frames)], colorReset, s.label)
			i++
		}
	}
}

func (s *spinner) stop(ok bool) {
	s.once.Do(func() {
		close(s.done)
		s.ticker.Stop()
		status := colorGreen + "done" + colorReset
		if !ok {
			status = colorRed + "failed" + colorReset
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
		// Match display labels like "...123" by account number suffix.
		if strings.HasPrefix(id, "...") {
			suffix := strings.TrimPrefix(id, "...")
			for num, acct := range byNumber {
				if strings.HasSuffix(num, suffix) {
					return acct.HashValue, nil
				}
			}
		}
		return "", fmt.Errorf("account not found: %s", id)
	}

	var ids []string
	switch {
	case strings.TrimSpace(override) != "":
		for _, part := range strings.Split(override, ",") {
			resolved, err := resolve(part)
			if err != nil {
				return nil, err
			}
			ids = append(ids, resolved)
		}
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
	fmt.Printf("%sTendies Realized P&L (%s)%s\n", colorDim, time.Now().In(loc).Format("2006-01-02 15:04:05 MST"), colorReset)
	if len(accounts) == 1 {
		fmt.Printf("%sAccount: %s%s\n", colorDim, accounts[0], colorReset)
	} else {
		fmt.Printf("%sAccounts: %s%s\n", colorDim, strings.Join(accounts, ", "), colorReset)
	}
	fmt.Println()
	fmt.Printf("%s%-8s %14s %14s %14s %8s%s\n", colorDim, "Period", "Gains", "Losses", "Net", "Trades", colorReset)
	fmt.Printf("%s%s%s\n", colorDim, strings.Repeat("─", 66), colorReset)
	for _, r := range results {
		gains := colorPadLeft(formatMoney(r.Summary.TotalGain), 14, colorGreen)
		losses := colorPadLeft(formatMoney(r.Summary.TotalLoss), 14, colorRed)
		net := colorPadLeft(formatSignedMoney(r.Summary.NetGain), 14, "")
		if r.Summary.NetGain > 0 {
			net = colorPadLeft(formatSignedMoney(r.Summary.NetGain), 14, colorGreen)
		} else if r.Summary.NetGain < 0 {
			net = colorPadLeft(formatSignedMoney(r.Summary.NetGain), 14, colorRed)
		}
		fmt.Printf("%-8s %s %s %s %8d\n",
			r.Label,
			gains,
			losses,
			net,
			r.Summary.TradeCount,
		)
	}
}

func printTrades(results []timeframeResult) {
	for _, r := range results {
		trades := r.Summary.Trades
		if len(trades) == 0 {
			continue
		}

		sort.Slice(trades, func(i, j int) bool {
			return trades[i].CloseTime.Before(trades[j].CloseTime)
		})

		fmt.Println()
		if len(results) > 1 {
			fmt.Printf("%s trades:\n", r.Label)
		} else {
			fmt.Println("Trades:")
		}
		fmt.Printf("  %s%-17s %-30s %8s %12s %8s%s\n", colorDim, "Time", "Symbol", "Qty", "P&L", "Hold", colorReset)
		fmt.Printf("  %s%s%s\n", colorDim, strings.Repeat("─", 79), colorReset)

		for _, t := range trades {
			hold := holdDuration(t)
			pnl := colorPadLeft(formatSignedMoney(t.RealizedPnL), 12, "")
			if t.RealizedPnL > 0 {
				pnl = colorPadLeft(formatSignedMoney(t.RealizedPnL), 12, colorGreen)
			} else if t.RealizedPnL < 0 {
				pnl = colorPadLeft(formatSignedMoney(t.RealizedPnL), 12, colorRed)
			}
			fmt.Printf("  %-17s %-30s %8.0f %s %8s\n",
				t.CloseTime.Local().Format("Jan 02 15:04"),
				truncate(t.Symbol, 30),
				t.Quantity,
				pnl,
				hold,
			)
		}
	}
}

func holdDuration(t schwab.ClosedTrade) string {
	if len(t.MatchedOpenings) == 0 {
		return "-"
	}
	// Use the earliest opening time for hold duration.
	earliest := t.MatchedOpenings[0].OpenTime
	for _, m := range t.MatchedOpenings[1:] {
		if m.OpenTime.Before(earliest) {
			earliest = m.OpenTime
		}
	}
	d := t.CloseTime.Sub(earliest)
	if d < 0 {
		return "-"
	}
	days := int(d.Hours()) / 24
	hours := int(d.Hours()) % 24
	mins := int(d.Minutes()) % 60
	switch {
	case days > 0:
		return fmt.Sprintf("%dd%dh", days, hours)
	case hours > 0:
		return fmt.Sprintf("%dh%dm", hours, mins)
	default:
		return fmt.Sprintf("%dm", mins)
	}
}

// insertCommas inserts thousand-separator commas into an integer string.
func insertCommas(s string) string {
	n := len(s)
	if n <= 3 {
		return s
	}
	// Number of commas needed.
	commas := (n - 1) / 3
	buf := make([]byte, n+commas)
	// First group length (1-3 digits before the first comma).
	first := n - commas*3
	copy(buf, s[:first])
	pos := first
	for i := first; i < n; i += 3 {
		buf[pos] = ','
		pos++
		copy(buf[pos:], s[i:i+3])
		pos += 3
	}
	return string(buf)
}

func formatMoney(v float64) string {
	sign := ""
	if v < 0 {
		sign = "-"
		v = -v
	}
	raw := fmt.Sprintf("%0.2f", v)
	parts := strings.SplitN(raw, ".", 2)
	return sign + "$" + insertCommas(parts[0]) + "." + parts[1]
}

func formatSignedMoney(v float64) string {
	if v > 0 {
		return "+" + formatMoney(v)
	}
	return formatMoney(v)
}

// colorPadLeft pads visible text to width, then wraps with ANSI color codes.
// This avoids alignment bugs where fmt width miscounts invisible escape chars.
func colorPadLeft(text string, width int, color string) string {
	pad := width - len(text)
	if pad <= 0 {
		return color + text + colorReset
	}
	return strings.Repeat(" ", pad) + color + text + colorReset
}

func colorizeMoney(v float64) string {
	formatted := formatSignedMoney(v)
	if v > 0 {
		return colorGreen + formatted + colorReset
	}
	if v < 0 {
		return colorRed + formatted + colorReset
	}
	return formatMoney(v)
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

// JSON output functions

func outputJSON(results []timeframeResult, accounts []string, accountIDs []string, warnings []string, now time.Time) error {
	output := buildJSONOutput(results, accounts, accountIDs, warnings, now)
	data, err := json.MarshalIndent(output, "", "  ")
	if err != nil {
		return fmt.Errorf("failed to marshal JSON output: %w", err)
	}
	_, err = os.Stdout.Write(data)
	if err != nil {
		return err
	}
	fmt.Fprintln(os.Stdout)
	return nil
}

func buildJSONOutput(results []timeframeResult, accounts []string, accountIDs []string, warnings []string, now time.Time) *schwab.JSONOutput {
	output := &schwab.JSONOutput{
		Accounts:   accounts,
		AccountIDs: accountIDs,
		Warnings:   uniqueStrings(warnings),
		UpdatedAt:  now.Format(time.RFC3339),
	}
	if output.Warnings == nil {
		output.Warnings = []string{}
	}
	if output.Accounts == nil {
		output.Accounts = []string{}
	}
	if output.AccountIDs == nil {
		output.AccountIDs = []string{}
	}

	for _, r := range results {
		includeCloses := r.Label == "Day" || r.Label == "Week"
		tf := schwab.JSONTimeframe{
			Label:      r.Label,
			Gains:      roundCents(r.Summary.TotalGain),
			Losses:     roundCents(r.Summary.TotalLoss),
			Net:        roundCents(r.Summary.NetGain),
			TradeCount: r.Summary.TradeCount,
			Tickers:    buildJSONTickers(r.Summary.Trades, includeCloses),
		}
		output.Timeframes = append(output.Timeframes, tf)
	}

	if output.Timeframes == nil {
		output.Timeframes = []schwab.JSONTimeframe{}
	}

	return output
}

func buildJSONTickers(trades []schwab.ClosedTrade, includeCloses bool) []schwab.JSONTicker {
	type tickerData struct {
		trades []schwab.ClosedTrade
		net    float64
	}
	groups := make(map[string]*tickerData)
	var order []string

	for _, t := range trades {
		key := t.Symbol
		if _, exists := groups[key]; !exists {
			groups[key] = &tickerData{}
			order = append(order, key)
		}
		groups[key].trades = append(groups[key].trades, t)
		groups[key].net += t.RealizedPnL
	}

	sort.Strings(order)

	tickers := make([]schwab.JSONTicker, 0, len(order))
	for _, sym := range order {
		data := groups[sym]
		ticker := schwab.JSONTicker{
			Symbol:     sym,
			Net:        roundCents(data.net),
			TradeCount: len(data.trades),
		}

		if underlying, expiry, strike, optType, ok := schwab.ParseOCCSymbol(sym); ok {
			ticker.Type = "option"
			ticker.Underlying = underlying
			ticker.Expiry = expiry
			ticker.Strike = strike
			ticker.OptionType = optType
			ticker.Display = formatOptionDisplay(underlying, expiry, strike, optType)
		} else {
			ticker.Type = "equity"
			ticker.Display = strings.TrimSpace(sym)
		}

		if includeCloses {
			closes := make([]schwab.JSONClose, 0, len(data.trades))
			for _, t := range data.trades {
				c := schwab.JSONClose{
					Time:     t.CloseTime.Format(time.RFC3339),
					Side:     t.CloseInstruction,
					Quantity: t.Quantity,
					Price:    t.ClosePrice,
					PnL:      roundCents(t.RealizedPnL),
				}
				for _, m := range t.MatchedOpenings {
					c.MatchedOpens = append(c.MatchedOpens, schwab.JSONMatchedOpen{
						Time:     m.OpenTime.Format(time.RFC3339),
						Side:     m.OpenInstruction,
						Quantity: m.Quantity,
						Price:    m.OpenPrice,
					})
				}
				if c.MatchedOpens == nil {
					c.MatchedOpens = []schwab.JSONMatchedOpen{}
				}
				closes = append(closes, c)
			}
			ticker.Closes = closes
		}
		// When includeCloses is false, Closes stays nil → JSON "null"

		tickers = append(tickers, ticker)
	}

	if tickers == nil {
		tickers = []schwab.JSONTicker{}
	}

	return tickers
}


func formatOptionDisplay(underlying, expiry string, strike float64, optionType string) string {
	t, err := time.Parse("2006-01-02", expiry)
	if err != nil {
		return underlying
	}
	typeChar := "C"
	if optionType == "PUT" {
		typeChar = "P"
	}
	strikeStr := strconv.FormatFloat(strike, 'f', -1, 64)
	return fmt.Sprintf("%s %s $%s%s", underlying, t.Format("01/02"), strikeStr, typeChar)
}

func roundCents(v float64) float64 {
	return math.Round(v*100) / 100
}

func writeJSONError(err error) {
	code, msg := classifyError(err)
	errObj := map[string]string{
		"error":   code,
		"message": msg,
	}
	data, _ := json.Marshal(errObj)
	fmt.Fprintln(os.Stderr, string(data))
}

func classifyError(err error) (string, string) {
	var subErr *broker.SubscriptionError
	if errors.As(err, &subErr) {
		return "subscription_required", subErr.Message
	}
	var rlErr *broker.RateLimitError
	if errors.As(err, &rlErr) {
		return "rate_limit_exceeded", rlErr.Message
	}
	var tfErr *broker.TimeframeError
	if errors.As(err, &tfErr) {
		return "timeframe_restricted", tfErr.Message
	}

	msg := err.Error()
	msgLower := strings.ToLower(msg)
	switch {
	case strings.Contains(msgLower, "schwab session expired"),
		strings.Contains(msgLower, "failed to refresh oauth token"):
		return "schwab_token_expired", msg
	case strings.Contains(msgLower, "no broker token in keychain"),
		strings.Contains(msgLower, "no oauth token in keychain"),
		strings.Contains(msgLower, "broker_client_id not set"),
		strings.Contains(msgLower, "missing schwab credentials"):
		return "auth_expired", msg
	default:
		return "generic", msg
	}
}
