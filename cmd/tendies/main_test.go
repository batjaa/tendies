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

func TestInsertCommas(t *testing.T) {
	t.Parallel()

	tests := []struct {
		in, want string
	}{
		{"0", "0"},
		{"12", "12"},
		{"123", "123"},
		{"1234", "1,234"},
		{"12345", "12,345"},
		{"123456", "123,456"},
		{"1234567", "1,234,567"},
		{"10000000", "10,000,000"},
	}
	for _, tc := range tests {
		if got := insertCommas(tc.in); got != tc.want {
			t.Errorf("insertCommas(%q) = %q, want %q", tc.in, got, tc.want)
		}
	}
}

func TestFormatMoney(t *testing.T) {
	t.Parallel()

	tests := []struct {
		in   float64
		want string
	}{
		{0, "$0.00"},
		{1500, "$1,500.00"},
		{-265.44, "-$265.44"},
		{1234567.89, "$1,234,567.89"},
		{0.50, "$0.50"},
	}
	for _, tc := range tests {
		if got := formatMoney(tc.in); got != tc.want {
			t.Errorf("formatMoney(%v) = %q, want %q", tc.in, got, tc.want)
		}
	}
}

func TestFormatSignedMoney(t *testing.T) {
	t.Parallel()

	tests := []struct {
		in   float64
		want string
	}{
		{0, "$0.00"},
		{1500, "+$1,500.00"},
		{-265.44, "-$265.44"},
	}
	for _, tc := range tests {
		if got := formatSignedMoney(tc.in); got != tc.want {
			t.Errorf("formatSignedMoney(%v) = %q, want %q", tc.in, got, tc.want)
		}
	}
}

func TestColorPadLeft(t *testing.T) {
	t.Parallel()

	// No color: plain padding.
	got := colorPadLeft("$50.00", 14, "")
	want := "        " + "" + "$50.00" + colorReset
	if got != want {
		t.Errorf("colorPadLeft no-color:\n got %q\nwant %q", got, want)
	}

	// With color: padding outside, color wrapping text.
	got = colorPadLeft("$50.00", 14, colorGreen)
	want = "        " + colorGreen + "$50.00" + colorReset
	if got != want {
		t.Errorf("colorPadLeft green:\n got %q\nwant %q", got, want)
	}

	// Text wider than width: no padding, still colored.
	got = colorPadLeft("$1,234,567.89", 10, colorRed)
	want = colorRed + "$1,234,567.89" + colorReset
	if got != want {
		t.Errorf("colorPadLeft overflow:\n got %q\nwant %q", got, want)
	}
}

