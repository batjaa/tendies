package main

import (
	"testing"
	"time"

	"github.com/batjaa/tendies/internal/schwab"
)

func TestValidateTimeframeFlags(t *testing.T) {
	t.Parallel()

	if err := validateTimeframeFlags(&cliOptions{showDay: true, showMonth: true}); err == nil {
		t.Fatal("expected validation error for multiple timeframe flags")
	}
	if err := validateTimeframeFlags(&cliOptions{showWeek: true}); err != nil {
		t.Fatalf("unexpected error for single timeframe flag: %v", err)
	}
}

func TestSelectedTimeframesDefault(t *testing.T) {
	t.Parallel()

	loc := time.FixedZone("MST", -7*60*60)
	now := time.Date(2025, time.January, 5, 15, 4, 5, 0, loc) // Sunday

	tfs := selectedTimeframes(&cliOptions{}, now)
	if len(tfs) != 4 {
		t.Fatalf("expected 4 timeframes, got %d", len(tfs))
	}

	if tfs[0].Name != "Day" || !tfs[0].From.Equal(time.Date(2025, time.January, 5, 0, 0, 0, 0, loc)) {
		t.Fatalf("unexpected day timeframe: %+v", tfs[0])
	}
	if tfs[1].Name != "Week" || !tfs[1].From.Equal(time.Date(2024, time.December, 30, 0, 0, 0, 0, loc)) {
		t.Fatalf("unexpected week timeframe: %+v", tfs[1])
	}
	if tfs[2].Name != "Month" || !tfs[2].From.Equal(time.Date(2025, time.January, 1, 0, 0, 0, 0, loc)) {
		t.Fatalf("unexpected month timeframe: %+v", tfs[2])
	}
	if tfs[3].Name != "Year" || !tfs[3].From.Equal(time.Date(2025, time.January, 1, 0, 0, 0, 0, loc)) {
		t.Fatalf("unexpected year timeframe: %+v", tfs[3])
	}
}

func TestSelectAccounts(t *testing.T) {
	t.Parallel()

	accounts := []schwab.AccountNumber{
		{AccountNumber: "1111", HashValue: "hash-b"},
		{AccountNumber: "2222", HashValue: "hash-a"},
	}

	selected, err := selectAccounts(accounts, []string{"hash-b", "1111", "hash-a"}, "")
	if err != nil {
		t.Fatalf("selectAccounts returned error: %v", err)
	}
	if len(selected) != 2 || selected[0] != "hash-a" || selected[1] != "hash-b" {
		t.Fatalf("unexpected selected accounts: %#v", selected)
	}

	override, err := selectAccounts(accounts, []string{"hash-b"}, "2222")
	if err != nil {
		t.Fatalf("override selection returned error: %v", err)
	}
	if len(override) != 1 || override[0] != "hash-a" {
		t.Fatalf("unexpected override account: %#v", override)
	}
}

func TestParseOAuthInput(t *testing.T) {
	t.Parallel()

	// Bare codes must be rejected (CSRF protection).
	if _, _, err := parseOAuthInput("abc123"); err == nil {
		t.Fatal("expected error for bare code input")
	}

	// Full callback URL with code and state.
	code, state, err := parseOAuthInput("https://127.0.0.1:8443/callback?code=xyz&state=s123")
	if err != nil || code != "xyz" || state != "s123" {
		t.Fatalf("unexpected URL parse result: code=%q state=%q err=%v", code, state, err)
	}

	// Callback URL without state — should parse with empty state.
	code, state, err = parseOAuthInput("https://127.0.0.1:8443/callback?code=onlycode")
	if err != nil || code != "onlycode" || state != "" {
		t.Fatalf("unexpected no-state URL parse result: code=%q state=%q err=%v", code, state, err)
	}

	// Missing code in callback URL.
	if _, _, err := parseOAuthInput("https://127.0.0.1:8443/callback?state=missing_code"); err == nil {
		t.Fatal("expected error when callback URL has no code")
	}
}
