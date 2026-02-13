# 🍗 Tendies

> Realized Schwab P&L in your terminal.

[![Go Version](https://img.shields.io/badge/go-1.25.7%2B-00ADD8?logo=go)](https://go.dev/)
[![Platform](https://img.shields.io/badge/platform-macOS-lightgrey)](#requirements)
[![License](https://img.shields.io/badge/license-private-informational)](#)

Tendies is a CLI that computes realized gains/losses from Schwab transactions for:
- Day (local midnight → now)
- Week (Monday 00:00 local → now)
- Month (1st day 00:00 local → now)
- Year (Jan 1 00:00 local → now)

It supports account selection, symbol filtering, and deep lot-matching debug output.

## ✨ Highlights
- OAuth login flow (`tendies login`)
- Realized P&L by day/week/month/year
- Account picker by hash or account number (`--account`)
- Symbol/underlying filtering (`--symbol=NVDA,TSLA`)
- Rich debug mode with matched opening lots (`--debug`)
- Account display-name cache with 7-day TTL (`display_name_cache`)

## ✅ Requirements
- Go `1.25.7+`
- Schwab developer app credentials
- macOS keychain access (OAuth token storage via `go-keyring`)

## 🚀 Quick Start
### 1) Build (or run directly)

```bash
go build -o tendies ./cmd/tendies
./tendies --help
```

or

```bash
go run ./cmd/tendies --help
```

### 2) Initialize config

```bash
tendies --config
# or: go run ./cmd/tendies --config
```

Then edit `~/.tendies/config.json` and set:
- `client_id`
- `client_secret`
- `redirect_url` (default: `https://127.0.0.1:8443/callback`)

### 3) Login

```bash
tendies login
```

### 4) Run

```bash
tendies
```

## 📚 Commands
| Command | Description |
|---|---|
| `tendies` | Calculate and print realized P&L |
| `tendies login` | Authenticate with Schwab and store OAuth token in keychain |
| `tendies accounts` | List accounts (number/hash/name/selected) |
| `tendies completion` | Generate shell completion scripts |

## 🎛️ Flags
### Main flags (`tendies`)
| Flag | Description |
|---|---|
| `--day` | Show day-to-date only |
| `--week` | Show week-to-date only |
| `--month` | Show month-to-date only |
| `--year` | Show year-to-date only |
| `--account <id>` | Use a specific account hash or account number |
| `--symbol <csv>` | Filter results by symbol/underlying (example: `HD,MU`) |
| `--debug` | Print verbose lot-matching and per-trade diagnostics |
| `--config` | Initialize/show config |
| `--refresh-details` | Force refresh cached account display details |

### Global flags (subcommands)
- `tendies accounts` supports `--refresh-details`
- `tendies login` also accepts global flags, though `--refresh-details` is typically irrelevant there

## 🧠 Account Name Cache
Display names are cached in `~/.tendies/config.json` under `display_name_cache`.

Behavior:
- TTL: **7 days**
- Fresh cache entries are reused
- Missing/stale entries are fetched from Schwab
- `--refresh-details` forces refresh

## 🔍 Debug Mode
`--debug` prints:
- timeframe boundaries
- per-account summary (gains/losses/net/trades)
- calculation warnings
- closed trades with:
  - close `activityId`
  - symbol
  - qty
  - open cash / close cash / P&L
  - matched opening lots (open activity/time/qty/cash-per-unit)

> [!WARNING]
> Debug mode may expose sensitive trading details in terminal logs/history.
> Use at your own risk.

## ⚠️ Calculation Limitations
- Results may be inaccurate in some cases due to Schwab API limitations and data-model gaps.
- This version is best used for **daily** or other short timeframes.
- Longer historical windows may be less reliable.
- **Wash sales are not modeled** in this version.
- **Tax-lot optimization / broker tax adjustments are not modeled** in this version.

## 🧪 Examples

```bash
# all default timeframes
tendies

# one timeframe
tendies --month

# one account
tendies --year --account <account_number_or_hash>

# filter to symbols/underlyings
tendies --month --symbol=NVDA,TSLA

# verbose lot matching
tendies --month --debug --symbol=NVDA

# refresh cached account names
tendies accounts --refresh-details
```

## 🛠️ Dev Utility
`cmd/checktypes` is a local debug helper that prints:
- closing trades in a rolling 7-day range
- trades pulled and attempted for matching

Run:

```bash
go run ./cmd/checktypes
```

## 🔐 Security
- OAuth token is stored in system keychain
- Remaining security hardening tasks are tracked in `TODO.md`
