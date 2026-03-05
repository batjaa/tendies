# Tendies Menu Bar App — UI Design

A native macOS status bar app that shows your realized trading P&L at a glance, like battery percentage but for your gains.

Interactive mockup: [docs/mockup.html](mockup.html)

---

## 1. Menu Bar Label

The label lives in the macOS status bar (top-right, next to Wi-Fi/battery). It shows one number: the net P&L for the configured timeframe (default: Day, configurable in Settings).

### States

```
Normal (positive):     ▲ +$1,234
Normal (negative):     ▼ -$567
Normal (zero):         ● $0
Loading:               ◌ $---
Error:                 ⚠ $---
Weekend fallback:      ▲ +$3,456 (w)
```

**Design notes:**
- The triangle (▲/▼) gives directionality even without color, since macOS menu bar is monochrome in light mode
- In dark mode the system tints menu bar items white; in light mode they're black. No custom colors in the label itself — the triangle handles positive/negative signaling
- When P&L exceeds $10K, abbreviate: `▲ +$12.3K`. Over $1M: `▲ +$1.2M`
- The label should be compact — aim for ~10-12 characters max
- During refresh, the existing value stays visible (no flicker). Only show `◌ $---` on first load

### Label Format Rules

| Net P&L | Label |
|---|---|
| +$1,234.56 | `▲ +$1,234` |
| -$567.89 | `▼ -$567` |
| +$12,345.67 | `▲ +$12.3K` |
| -$100,000.00 | `▼ -$100K` |
| $0.00 | `● $0` |
| Loading (first time) | `◌ $---` |
| Error | `⚠ $---` |

**Rounding:** Whole dollars for values under $10K. One decimal + K/M suffix above.

### Weekend / Holiday Fallback

When the configured menu bar timeframe (default: Day) has $0 P&L and 0 trades (i.e., no trading activity), the label falls back to **Week** P&L. Only Week is used as a fallback — no further cascade to Month.

The label appends a `(w)` suffix to indicate the fallback: `▲ +$3,456 (w)`. Inside the popover, a subtle note below the timeframe list reads "Showing Week — no Day trades".

---

## 2. Dropdown Popover

Click the menu bar label to open a floating popover window (SwiftUI `MenuBarExtra` with `.window` style). This gives full layout control unlike the `.menu` style. Width: **300pt**.

### 2.1 Default State — Loaded

```
┌───────────────────────────────────────┐
│  Tendies                      ↻  ⚙  │
│───────────────────────────────────────│
│  Acct  [···789] [···234]  ···567     │
│                                       │
│  ▸ Day      +$1,234.56   12 trades   │
│  ▸ Week     +$3,456.78   45 trades   │
│  ▸ Month    -$2,100.00   98 trades   │
│                                       │
│───────────────────────────────────────│
│  Updated 2m ago               Quit   │
└───────────────────────────────────────┘

[···789] [···234] = selected (highlighted border)
 ···567           = unselected (dim)
```

**Layout breakdown:**

| Element | Position | Details |
|---|---|---|
| "Tendies" | Top-left | App name, semibold |
| ↻ (refresh) | Top-right | Circular arrow button, triggers immediate refresh |
| ⚙ (settings) | Top-right | Opens settings view |
| Account chips | Below header | Toggleable chips for each account; at least one must stay selected |
| Timeframe rows | Center | Up to 3 expandable rows: Day, Week, Month (Year disabled for now) |
| "Updated X ago" | Bottom-left | Relative timestamp |
| "Quit" | Bottom-right | Terminates the app |

**Note:** Year is excluded from the popover due to wide P&L miscalculation. It will be re-enabled once FIFO matching accuracy improves.

### 2.2 Account Selector

Account chips appear below the header separator. Each chip shows the account's display label (e.g., `...789`).

- Clicking a chip toggles it on/off
- At least one account must remain selected (can't deselect all)
- Selected chips have a brighter border and text; unselected chips are dim
- When multiple accounts are selected, P&L data is aggregated across all of them
- Toggling an account triggers an immediate re-fetch from the CLI with the updated account selection
- If the chip row overflows the popover width, it scrolls horizontally (no wrapping)

### 2.3 Timeframe Rows

Each row has four columns:

```
  ▸ Day      +$1,234.56    12 trades
  ─  ───      ──────────    ─────────
  │  label    net P&L       trade count
  │           (colored)     (tertiary)
  chevron
```

- **Chevron (▸)** indicates the row is expandable — rotates to ▾ when expanded
- **Net P&L** is green for positive, red for negative
- Clicking a row **expands it** to show the ticker drill-down (see 2.4)
- **Accordion behavior:** only one timeframe can be expanded at a time; expanding a new one collapses the previous
- Within an expanded timeframe, multiple tickers can be expanded simultaneously

### 2.4 Drill-Down: Timeframe → Tickers

Clicking a timeframe row expands it to reveal per-ticker P&L. This is the primary way to see which symbols contributed to the day's gains or losses.

```
┌───────────────────────────────────────┐
│  Tendies                      ↻  ⚙  │
│───────────────────────────────────────│
│  Acct  [···789] [···234]             │
│                                       │
│  ▾ Day      +$1,234.56   12 trades   │
│  ┌─────────────────────────────────┐  │
│  │ ▸ HD       +$892.30     4 exe   │  │
│  │ ▸ META     +$567.26     3 exe   │  │
│  │   MU       -$225.00     2 exe   │  │
│  └─────────────────────────────────┘  │
│  ▸ Week     +$3,456.78   45 trades   │
│  ▸ Month    -$2,100.00   98 trades   │
│                                       │
│───────────────────────────────────────│
│  Updated 2m ago               Quit   │
└───────────────────────────────────────┘
```

**Ticker row columns:**

| Column | Details |
|---|---|
| Chevron (▸) | Shown only if execution data is available; expands to show matched trades |
| Symbol | Monospace, bold — stock ticker (e.g., `HD`) or options contract (e.g., `HD 03/07 $380C`) |
| Net P&L | Green/red, monospace |
| Execution count | Tertiary text (e.g., `4 exe`) |

**Ticker grouping:**
- **Stocks:** grouped by ticker symbol (e.g., all HD equity trades under one row)
- **Options:** grouped by underlying + strike + expiry (e.g., `HD 03/07 $380C` is one row, `HD 03/07 $385P` is a separate row)

**Sort order:** Alphabetical by symbol (default). Can be changed in Settings to sort by absolute P&L descending (biggest movers first).

**No chevron rule:** If a ticker has no execution-level data (Month timeframe, or trades not available), the chevron is hidden and the row is not expandable.

### 2.5 Drill-Down: Ticker → Executions (Grouped by Close)

Clicking a ticker row expands to show closing trades, each with their FIFO-matched opening legs nested underneath.

```
│  ▾ HD       +$892.30     4 exe          │
│  ┊ ▾ 9:47   SELL  50sh @ $385.10  +$132.50 │
│  ┊   └ opened 9:32  50sh @ $382.45         │
│  ┊ ▾ 10:15  SELL  50sh @ $397.68  +$759.80 │
│  ┊   └ opened 9:32  50sh @ $382.45         │
```

Options example:
```
│  ▾ HD 03/07 $380C            +$340.00  2 exe │
│  ┊ ▾ 10:30  SELL_TO_CLOSE 5 @ $4.20  +$340.00│
│  ┊   └ opened 9:45  5 @ $3.52                │
```

**Structure:**
- **Primary rows** are closing trades (SELL / SELL_TO_CLOSE) — these are the rows with realized P&L
- **Nested rows** are the matched opening legs from FIFO lot matching, indented with a `└` connector
- A closing trade may have multiple matched opens (scale-in scenario):
  ```
  ▾ 11:00  SELL 200sh @ $100.50        +$150.00
    ├ opened 9:30  100sh @ $99.75
    └ opened 9:45  100sh @ $100.00
  ```

**Execution row columns:**

| Column | Details |
|---|---|
| Time | `HH:MM`, monospace, tertiary |
| Side | `SELL` / `SELL_TO_CLOSE` for closes; `opened HH:MM` for matched opens |
| Size + price | `{qty}sh @ ${price}` (stocks) or `{qty} @ ${price}` (options) |
| P&L | Realized P&L on the closing row; not shown on opening rows |

**Data availability:** Execution-level data is included for Day and Week timeframes only. Month rows hide the chevron and are not expandable.

### 2.6 Loading State (First Launch)

On first launch (or when no cached data exists), the app fires a single `tendies --json` call. While waiting, the popover shows per-timeframe skeleton rows:

```
┌───────────────────────────────────────┐
│  Tendies                      ↻  ⚙  │
│───────────────────────────────────────│
│                                       │
│  ◌ Day      ···              ···     │
│  ◌ Week     ···              ···     │
│  ◌ Month    ···              ···     │
│                                       │
│───────────────────────────────────────│
│                               Quit   │
└───────────────────────────────────────┘
```

Each row shows a subtle loading indicator (◌ or shimmer placeholder) until the CLI response arrives and all rows populate at once.

If the popover isn't open during first load, the menu bar shows `◌ $---` until data is ready.

### 2.7 Refreshing State (Background Update)

When auto-refresh or manual refresh fires, the previous data stays visible. The refresh icon spins. Footer shows "Updating...":

```
┌───────────────────────────────────────┐
│  Tendies                     ↻⟳  ⚙  │
│───────────────────────────────────────│
│  Acct  [···789]                       │
│                                       │
│  ▸ Day      +$1,234.56   12 trades   │
│  ▸ Week     +$3,456.78   45 trades   │
│  ▸ Month    -$2,100.00   98 trades   │
│                                       │
│───────────────────────────────────────│
│  Updating...                  Quit   │
└───────────────────────────────────────┘
```

The key UX principle: **never flash the whole view to a loading state on refresh**. Only the first load shows skeletons. Subsequent refreshes update data in-place.

If the user has a drill-down expanded and the refresh completes:
- Expanded state is preserved
- Data updates in-place
- If an expanded ticker no longer exists in the new data (e.g., trade reclassified to a different day), the drill-down collapses gracefully

### 2.8 Error State — Broker Auth Expired

```
┌───────────────────────────────────────┐
│  Tendies                      ↻  ⚙  │
│───────────────────────────────────────│
│                                       │
│  ⚠  Authentication expired           │
│                                       │
│  Run in Terminal:                     │
│  ┌─────────────────────────────────┐  │
│  │ tendies auth login                   │  │
│  └─────────────────────────────────┘  │
│         ⎘ Copy Command                │
│                                       │
│  After logging in, click refresh      │
│  to reload your data.                 │
│                                       │
│───────────────────────────────────────│
│                               Quit   │
└───────────────────────────────────────┘
```

- The `Copy Command` button copies `tendies auth login` to clipboard
- After re-auth, clicking ↻ refresh recovers automatically

### 2.9 Error State — Schwab Token Expired

Distinct from broker auth — this occurs when the underlying Schwab refresh token has expired (7-day window). The broker backend cannot refresh it on its own.

```
┌───────────────────────────────────────┐
│  Tendies                      ↻  ⚙  │
│───────────────────────────────────────│
│                                       │
│  ⚠  Schwab session expired           │
│                                       │
│  Your Schwab token has expired.       │
│  Re-authenticate in Terminal:         │
│  ┌─────────────────────────────────┐  │
│  │ tendies auth login                   │  │
│  └─────────────────────────────────┘  │
│         ⎘ Copy Command                │
│                                       │
│───────────────────────────────────────│
│                               Quit   │
└───────────────────────────────────────┘
```

The CLI (or broker backend) should return a distinct error code/message so the app can differentiate this from a broker auth expiry.

### 2.10 Error State — Binary Not Found

```
┌───────────────────────────────────────┐
│  Tendies                      ↻  ⚙  │
│───────────────────────────────────────│
│                                       │
│  ⚠  tendies CLI not found            │
│                                       │
│  Install via Homebrew:                │
│  ┌─────────────────────────────────┐  │
│  │ brew install batjaa/tap/tendies │  │
│  └─────────────────────────────────┘  │
│         ⎘ Copy Command                │
│                                       │
│  Then click refresh to load your      │
│  P&L data.                            │
│                                       │
│───────────────────────────────────────│
│                               Quit   │
└───────────────────────────────────────┘
```

### 2.11 Error State — Generic Error

```
┌───────────────────────────────────────┐
│  Tendies                      ↻  ⚙  │
│───────────────────────────────────────│
│                                       │
│  ⚠  Something went wrong             │
│                                       │
│  failed to load accounts:             │
│  connection refused                   │
│                                       │
│  Try refreshing or check your         │
│  network connection.                  │
│                                       │
│───────────────────────────────────────│
│                               Quit   │
└───────────────────────────────────────┘
```

### 2.12 Zero Trades State

```
┌───────────────────────────────────────┐
│  Tendies                      ↻  ⚙  │
│───────────────────────────────────────│
│  Acct  [···789]                       │
│                                       │
│  ▸ Day          $0.00    0 trades     │
│  ▸ Week     +$3,456.78   45 trades   │
│  ▸ Month    -$2,100.00   98 trades   │
│                                       │
│───────────────────────────────────────│
│  Updated just now             Quit   │
└───────────────────────────────────────┘
```

No special empty state — $0 with 0 trades is normal (market hasn't opened, no trades yet, etc.).

### 2.13 Weekend / Holiday Fallback

When Day has 0 trades and the menu bar label falls back to Week (see section 1), the popover shows a subtle note:

```
┌───────────────────────────────────────┐
│  Tendies                      ↻  ⚙  │
│───────────────────────────────────────│
│  Acct  [···789]                       │
│                                       │
│    Day          $0.00    0 trades     │
│  ▸ Week     +$3,456.78   45 trades   │
│  ▸ Month    -$2,100.00   98 trades   │
│                                       │
│  Showing Week — no Day trades         │
│───────────────────────────────────────│
│  Updated just now             Quit   │
└───────────────────────────────────────┘
```

The note text is tertiary/dim. The Week row gets the subtle highlight indicating it's driving the menu bar label.

---

## 3. Settings

Accessible via the ⚙ gear icon. Opens as a view within the popover with a "← Back" button to return.

### 3.1 Settings Panel

```
┌───────────────────────────────────────┐
│  Tendies                      ↻  ⚙  │
│───────────────────────────────────────│
│  ← Back                              │
│                                       │
│  DISPLAY                              │
│  Timeframes   [✓Day][✓Wk][✓Mo]      │
│  Menu Bar     [Day ▾]                │
│  Ticker Sort  [A-Z ▾]               │
│  Symbols      [________] (ALL)        │
│                                       │
│  REFRESH                              │
│  Interval     [1 min ▾]              │
│                                       │
│  GENERAL                              │
│  CLI Path     /opt/homebrew/bin/te... │
│  Mode         [Broker ▾]             │
│  Launch at Login          [━━●]  on  │
│                                       │
│───────────────────────────────────────│
└───────────────────────────────────────┘
```

### 3.2 Settings Reference

| Setting | Control | Default | Notes |
|---|---|---|---|
| Timeframes | Checkbox group (Day, Week, Month) | All checked | Which periods to show; at least one must stay checked. Year excluded for now. |
| Menu Bar | Dropdown (Day / Week / Month) | Day | Which timeframe drives the menu bar label |
| Ticker Sort | Dropdown (A-Z / P&L) | A-Z | Alphabetical or absolute P&L descending (biggest movers first) |
| Symbols | Text input, comma-separated | (empty = all) | Passed as `--symbol` to CLI |
| Refresh interval | Dropdown (1 / 2 / 5 / 10 / 30 min) | From config `refresh_mins` | How often to re-run the CLI |
| CLI path | Monospace display, editable on click | Auto-detected | Click to edit; accepts a file path to the `tendies` binary |
| Mode | Dropdown (Broker / Direct) | Broker | Passed as `--direct` flag to CLI |
| Launch at Login | Toggle switch | Off | Registers as a login item via `SMAppService` |

**Settings persistence:** Store in `UserDefaults` (standard for macOS apps). The CLI path and mode settings override what's in `~/.tendies/config.json` when running the CLI.

---

## 4. Interactions

### 4.1 Click Behaviors

| Target | Action |
|---|---|
| Menu bar label | Toggle popover open/closed |
| Account chip | Toggle account on/off → re-fetch from CLI. At least one must stay selected. |
| Timeframe row | Expand/collapse ticker drill-down (accordion: only one at a time) |
| Ticker row (with chevron) | Expand/collapse execution drill-down (multiple tickers can be open) |
| Ticker row (no chevron) | No action (Month tickers without execution data) |
| ↻ Refresh button | Trigger immediate CLI fetch |
| ⚙ Settings button | Navigate to settings view |
| ← Back (in settings) | Return to main view |
| Copy Command button | Copy command string to clipboard |
| Quit | `NSApplication.shared.terminate(nil)` |

### 4.2 Keyboard Shortcuts

| Shortcut | Action |
|---|---|
| `⌘R` | Refresh (when popover is open) |
| `⌘Q` | Quit |
| `⌘,` | Open settings |
| `Esc` | Close popover |

### 4.3 Auto-Refresh Behavior

- Timer fires every `refreshInterval` minutes
- Refresh runs in background; UI shows previous data until new data arrives
- If CLI returns error during auto-refresh, keep showing last good data + show a subtle warning indicator (small dot on the refresh icon)
- If CLI returns error 3 times consecutively, switch to error state
- A successful **manual** refresh resets the consecutive error counter
- Timer pauses when Mac sleeps (`NSWorkspace.willSleepNotification`), resumes on wake

### 4.4 Popover Dismissal

- Clicking outside the popover dismisses it (standard macOS behavior)
- Navigating to Settings keeps the popover open
- Drill-down state is preserved while the popover is open
- No auto-dismiss timer — the popover stays open until the user clicks outside

---

## 5. Visual Design

### 5.1 Design Language

Follow macOS system conventions:
- **Font:** SF Pro (system default), SF Mono for P&L numbers and symbols
- **Colors:** System semantic colors (`Color.primary`, `.secondary`, `.green`, `.red`)
- **Spacing:** 12px padding, 6px between rows, 6px row inset
- **Corner radius:** 10px popover, 6px rows, 4px chips
- **Width:** 300pt
- **Background:** `.ultraThinMaterial` (frosted glass, matches macOS popovers)

### 5.2 Color Usage

| Element | Light Mode | Dark Mode |
|---|---|---|
| Positive P&L | `#248A3D` | `#30D158` |
| Negative P&L | `#D70015` | `#FF453A` |
| Zero P&L | Primary text | Primary text |
| Labels (Day/Week) | Secondary text | Secondary text |
| Trade count / exe count | Tertiary text | Tertiary text |
| Active/expanded row bg | `rgba(0,0,0,0.04)` | `rgba(255,255,255,0.05)` |
| Hover row bg | `rgba(0,0,0,0.02)` | `rgba(255,255,255,0.03)` |
| Ticker list bg | `rgba(0,0,0,0.02)` | `rgba(255,255,255,0.02)` |
| Execution list border-left | Separator color | Separator color |
| Dividers | System separator | System separator |
| Selected account chip | Primary text, 14% border | Primary text, 14% border |
| Unselected account chip | Tertiary text, 6% border | Tertiary text, 6% border |
| Skeleton/loading placeholder | Tertiary text, 4% bg | Tertiary text, 4% bg |
| Fallback note text | Tertiary text | Tertiary text |

### 5.3 Typography

| Element | Font | Weight | Size |
|---|---|---|---|
| "Tendies" header | SF Pro | Semibold | 13pt |
| Timeframe label | SF Pro | Medium | 12.5pt |
| P&L value | SF Mono | Medium | 12.5pt |
| Trade count | SF Pro | Regular | 11pt |
| Ticker symbol | SF Mono | Semibold | 11.5pt |
| Ticker P&L | SF Mono | Medium | 11pt |
| Execution detail | SF Mono | Regular | 10pt |
| Execution side (SELL/etc.) | SF Mono | Medium | 10pt |
| Matched open row | SF Mono | Regular | 10pt |
| Account chip | SF Mono | Medium | 10.5pt |
| Account bar label | SF Pro | Medium | 10pt |
| Footer text | SF Pro | Regular | 11pt |
| Fallback note | SF Pro | Regular | 11pt |
| Error title | SF Pro | Medium | 12.5pt |
| Error body | SF Pro | Regular | 11.5pt |
| Error command | SF Mono | Regular | 12pt |
| Settings section title | SF Pro | Semibold | 10pt |
| Settings label | SF Pro | Regular | 12pt |

### 5.4 Animation

- **Refresh icon:** 360-degree rotation while fetching
- **Drill-down expand:** 0.15s ease (opacity + max-height)
- **Chevron rotation:** 0.15s ease (0° → 90° on expand)
- **Data update:** No animation (instant swap avoids jitter on frequent refreshes)
- **Settings transition:** In-place swap (← Back returns to main)
- **Popover appear:** Scale(0.96) + translateY(-4px) → normal, 0.2s cubic-bezier(0.16, 1, 0.3, 1)
- **Loading skeletons:** Subtle shimmer or pulse animation on placeholder bars

---

## 6. Data Flow

```
                    ┌──────────────┐
                    │  Timer fires │
                    │  (every Nm)  │
                    └──────┬───────┘
                           │
                           ▼
               ┌───────────────────────┐
               │  Run: tendies --json  │
               │  [--symbol X]         │
               │  [--direct]           │
               │  [--account hash,...] │
               └───────────┬───────────┘
                           │
                    ┌──────┴──────┐
                    │             │
                 exit 0        exit 1
                    │             │
                    ▼             ▼
            ┌─────────────┐ ┌──────────┐
            │ Parse JSON  │ │ Parse    │
            │ from stdout │ │ stderr   │
            └──────┬──────┘ └────┬─────┘
                   │             │
                   ▼             ▼
            ┌─────────────┐ ┌──────────┐
            │  .loaded()  │ │ .error() │
            │  Update UI  │ │ Show msg │
            └─────────────┘ └──────────┘
```

### CLI invocation

The app runs a single CLI call and all timeframe rows populate at once when the response arrives. While waiting, the popover shows per-row loading skeletons.

```bash
# Default: all timeframes, broker mode
tendies --json

# With symbol filter
tendies --json --symbol HD,MU

# Direct mode
tendies --json --direct

# Both
tendies --json --direct --symbol HD,MU
```

The `--json` flag (new) outputs structured JSON to stdout. Spinners/warnings go to stderr (ignored by the app).

### JSON contract

The JSON output includes per-timeframe summaries, per-ticker grouping, and execution-level detail with FIFO-matched opens:

```json
{
  "timeframes": [
    {
      "label": "Day",
      "gains": 1500.00,
      "losses": -265.44,
      "net": 1234.56,
      "trade_count": 12,
      "tickers": [
        {
          "symbol": "HD",
          "display": "HD",
          "type": "equity",
          "net": 892.30,
          "trade_count": 4,
          "closes": [
            {
              "time": "2026-03-04T09:47:00-05:00",
              "side": "SELL",
              "quantity": 50,
              "price": 385.10,
              "pnl": 132.50,
              "matched_opens": [
                {
                  "time": "2026-03-04T09:32:00-05:00",
                  "quantity": 50,
                  "price": 382.45
                }
              ]
            },
            {
              "time": "2026-03-04T10:15:00-05:00",
              "side": "SELL",
              "quantity": 50,
              "price": 397.68,
              "pnl": 759.80,
              "matched_opens": [
                {
                  "time": "2026-03-04T09:32:00-05:00",
                  "quantity": 50,
                  "price": 382.45
                }
              ]
            }
          ]
        },
        {
          "symbol": "HD 250307C380",
          "display": "HD 03/07 $380C",
          "type": "option",
          "underlying": "HD",
          "expiry": "2025-03-07",
          "strike": 380.0,
          "option_type": "CALL",
          "net": 340.00,
          "trade_count": 2,
          "closes": [
            {
              "time": "2026-03-04T10:30:00-05:00",
              "side": "SELL_TO_CLOSE",
              "quantity": 5,
              "price": 4.20,
              "pnl": 340.00,
              "matched_opens": [
                {
                  "time": "2026-03-04T09:45:00-05:00",
                  "quantity": 5,
                  "price": 3.52
                }
              ]
            }
          ]
        }
      ]
    },
    {
      "label": "Week",
      "gains": 5000.00,
      "losses": -1543.22,
      "net": 3456.78,
      "trade_count": 45,
      "tickers": [
        {
          "symbol": "HD",
          "display": "HD",
          "type": "equity",
          "net": 2140.50,
          "trade_count": 18,
          "closes": null
        }
      ]
    },
    {
      "label": "Month",
      "gains": 8200.00,
      "losses": -10300.00,
      "net": -2100.00,
      "trade_count": 98,
      "tickers": [
        {
          "symbol": "HD",
          "display": "HD",
          "type": "equity",
          "net": -1250.00,
          "trade_count": 42,
          "closes": null
        }
      ]
    }
  ],
  "accounts": ["...789", "...234"],
  "warnings": [],
  "updated_at": "2026-03-04T15:30:00-05:00"
}
```

**Field reference:**

| Field | Description |
|---|---|
| `tickers[].symbol` | Raw symbol (OCC format for options, e.g., `HD 250307C380`) |
| `tickers[].display` | Human-readable label (e.g., `HD 03/07 $380C`) |
| `tickers[].type` | `"equity"` or `"option"` |
| `tickers[].underlying` | (options only) Underlying ticker |
| `tickers[].expiry` | (options only) Expiration date `YYYY-MM-DD` |
| `tickers[].strike` | (options only) Strike price |
| `tickers[].option_type` | (options only) `"CALL"` or `"PUT"` |
| `tickers[].closes` | Array of closing trades with matched opens, or `null` if not available |
| `closes[].side` | `"SELL"`, `"SELL_TO_CLOSE"`, `"BUY_TO_CLOSE"`, etc. |
| `closes[].matched_opens` | FIFO-matched opening legs for this close |

**Notes:**
- `tickers[].closes` is populated for Day and Week, `null` for Month (too many executions to be useful)
- Year is excluded entirely from `--json` output for now
- Tickers are sorted alphabetically in the JSON; the app re-sorts per user setting
- The Go side parses OCC symbols into structured fields so the Swift app doesn't need to

### Error contract

On `exit 1`, stderr contains a JSON error object so the app can differentiate error types:

```json
{
  "error": "schwab_token_expired",
  "message": "Schwab refresh token expired — run `tendies auth login` to re-authenticate"
}
```

| `error` code | App behavior |
|---|---|
| `auth_expired` | Show broker auth expired state (2.8) |
| `schwab_token_expired` | Show Schwab session expired state (2.9) |
| `not_found` | Show binary not found state (2.10) — detected by app, not CLI |
| (anything else) | Show generic error state (2.11) with the `message` text |

---

## 7. Edge Cases

| Scenario | Behavior |
|---|---|
| Market closed (weekend/holiday) | Day shows $0 / 0 trades. Menu bar falls back to Week with `(w)` suffix. |
| Multiple accounts | Account chips shown; P&L aggregated across selected accounts. |
| Account toggled off | Re-fetch from CLI with remaining selected accounts. |
| Very long symbol filter | Truncate in settings display, pass full value to CLI. |
| Many tickers in drill-down | Popover height grows to a max then scrolls internally. |
| Ticker disappears after refresh | If expanded ticker is no longer in new data, collapse gracefully. |
| CLI takes >30s | Show timeout error after 30s, kill the process. |
| CLI hangs indefinitely | Watchdog timer kills process after 60s. |
| Mac wakes from sleep | Immediate refresh on wake, then resume normal timer. |
| First run, no config | CLI handles defaults. App shows whatever CLI outputs. |
| Rapid manual refreshes | Debounce: ignore refresh clicks within 5s of last fetch start. |
| App update while running | Not handled in v1. User quits and reopens. |
| Drill-down during refresh | Preserve expanded state; update data in-place when refresh completes. |
| All timeframes unchecked | Prevented — at least one checkbox must stay checked (same as accounts). |
| Schwab token expired | Distinct error state from broker auth (see 2.9). |
| 3+ accounts overflow chips | Chips scroll horizontally; no wrapping. |

---

## 8. Implementation Phases

### Phase 1: `--json` flag (Go CLI) — DONE
Add `--json` output to `cmd/tendies/main.go` with the contract above. Includes per-ticker grouping, structured option fields, and FIFO-matched execution detail. Error JSON on stderr.

### Phase 2: Core menu bar app (Swift) — DONE
- [x] Menu bar label with P&L (including weekend fallback, K/M abbreviation)
- [x] Popover with loading skeletons → loaded state
- [x] Account selector chips (horizontal scroll, re-fetch on toggle)
- [x] Timeframe → ticker drill-down (accordion)
- [x] Auto-refresh on timer
- [x] Manual refresh button
- [x] Error states (broker auth, Schwab token, binary not found, subscription, timeout, generic)
- [x] Login view with OAuth PKCE
- [x] Subscription/trial paywall
- [x] Quit button

### Phase 3: Execution drill-down & Settings

- [x] **3.1 Execution drill-down** — chronological list, merged opens, colored side labels (uncommitted)
- [ ] **3.2 Settings panel** — replace placeholder with full settings view
  - [ ] Timeframes: checkbox group (Day/Week/Month), at least one checked
  - [ ] Menu Bar Timeframe: picker (Day/Week/Month)
  - [ ] Ticker Sort: picker (A-Z / P&L desc)
  - [ ] Symbols: text field, comma-separated, passed as `--symbol`
  - [ ] Refresh Interval: picker (1/2/5/10/30 min), restart timer on change
  - [ ] CLI Path: text display, editable on click, overrides auto-detection
  - [ ] Mode: picker (Broker/Direct), passed as `--direct`
  - [ ] Launch at Login: toggle via `SMAppService` (macOS 13+)
  - [ ] Persistence: `@AppStorage` (UserDefaults) for all settings
- [x] **3.3 Keyboard shortcuts**
  - [x] `⌘R` → refresh
  - [x] `⌘Q` → quit
  - [x] `⌘,` → open settings
  - [x] `Esc` → close popover
- [x] **3.4 Sleep/wake handling**
  - [x] `NSWorkspace.willSleepNotification` → pause timer
  - [x] `NSWorkspace.didWakeNotification` → immediate refresh + restart timer
- [ ] **3.5 Consecutive error backoff**
  - [ ] Track `consecutiveErrors` count in AppState
  - [ ] After 3 consecutive errors, switch to error state
  - [ ] Manual refresh resets counter

### Phase 4: Distribution
- [ ] **4.1 Code signing & notarization** — Xcode archive, Developer ID, `notarytool`, staple
- [ ] **4.2 Homebrew cask** — formula in `batjaa/homebrew-tap`, GitHub Release .zip asset
- [ ] **4.3 Auto-update** (stretch) — Sparkle or manual GitHub releases check

---

## 9. Technical Requirements

- **macOS 13.0+** (required for `MenuBarExtra`)
- **Swift 5.9+** / Xcode 15+
- **No sandbox** (`com.apple.security.app-sandbox = false`) — needed to execute the Homebrew-installed `tendies` binary
- **LSUIElement = YES** — no Dock icon, menu bar only
- **Binary resolution:** Check `/opt/homebrew/bin/tendies`, `/usr/local/bin/tendies`, then PATH
- **Dark/light mode:** Full support via system semantic colors (see mockup theme toggle)
