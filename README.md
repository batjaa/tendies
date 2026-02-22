# Tendies

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

## Highlights
- OAuth login flow (`tendies login`)
- Realized P&L by day/week/month/year
- Account picker by hash or account number (`--account`)
- Symbol/underlying filtering (`--symbol=NVDA,TSLA`)
- Rich debug mode with matched opening lots (`--debug`)
- Account display-name cache with 7-day TTL

## Architecture

```
tendies/
├── backend/             # Laravel API (Passport OAuth + Schwab proxy)
├── cmd/tendies/         # Go CLI entry point
├── internal/
│   ├── broker/          # Broker API client (talks to backend)
│   ├── schwab/          # Direct Schwab API client + P&L engine
│   └── config/          # CLI config + keychain token storage
└── README.md
```

The CLI has two modes:

- **Broker mode** (default) — CLI authenticates via the Laravel backend, which proxies Schwab API calls. Users don't need their own Schwab developer credentials.
- **Direct mode** (`--direct`) — CLI talks to Schwab API directly. Requires your own Schwab app credentials.

### Auth Flow (Broker Mode)

```
CLI                         Backend                      Schwab
 │                            │                            │
 │  1. Start local server     │                            │
 │  2. Open browser ──────▶  /oauth/authorize              │
 │                            │  (no session)              │
 │                            │  3. Redirect ──────────▶  /v1/oauth/authorize
 │                            │                            │
 │                            │                 4. User consents
 │                            │                            │
 │                            │  5. Callback ◀──────────  ?code=SCHWAB_CODE
 │                            │  6. Exchange code → tokens │
 │                            │  7. Store encrypted tokens │
 │                            │  8. Log user in            │
 │                            │  9. Issue Passport code    │
 │  10. Receive callback ◀── redirect to 127.0.0.1:PORT   │
 │  11. Exchange code+PKCE    │                            │
 │      for Passport tokens   │                            │
 │  12. Save to keychain      │                            │
```

---

## Prerequisites

- **Go** 1.25.7+ (`go version`)
- **PHP** 8.2+ (`php -v`)
- **Composer** (`composer --version`)
- **Schwab developer app** — register at [developer.schwab.com](https://developer.schwab.com) to get a client ID and secret
- macOS keychain access (OAuth token storage via `go-keyring`)

---

## Backend Setup

```bash
cd backend
composer install
```

### Configure environment

```bash
cp .env.example .env
```

Edit `backend/.env` and fill in your Schwab credentials:

```
SCHWAB_CLIENT_ID=your-schwab-client-id
SCHWAB_CLIENT_SECRET=your-schwab-client-secret
SCHWAB_REDIRECT_URI=http://localhost:8000/auth/schwab/callback
```

### Run migrations

```bash
php artisan migrate
```

This uses SQLite by default (creates `database/database.sqlite` automatically).

### Create Passport client for the CLI

```bash
php artisan passport:client --public --name="tendies-cli"
```

Note the **Client ID** printed — you'll need it for the CLI config.

### Start the server

```bash
php artisan serve
```

Runs at `http://localhost:8000`. Verify:

```bash
curl http://localhost:8000/api/health
# {"status":"ok"}
```

### API Routes

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/health` | No | Health check |
| GET | `/api/v1/accounts` | Passport | List Schwab accounts |
| GET | `/api/v1/transactions` | Passport | Get transactions (query: `account_hash`, `start`, `end`, `types`) |
| GET | `/oauth/authorize` | Session | Passport authorization (redirects to Schwab if no session) |
| POST | `/oauth/token` | No | Exchange auth code for tokens |

---

## CLI Setup — Broker Mode

### 1. Initialize config

```bash
go run ./cmd/tendies --config
```

This creates `~/.tendies/config.json`. Edit it:

```json
{
  "broker_url": "http://localhost:8000",
  "broker_client_id": "PASTE_PASSPORT_CLIENT_ID_HERE"
}
```

### 2. Log in

```bash
go run ./cmd/tendies login
```

This opens your browser. You'll be redirected through Schwab OAuth, then back to the CLI. On success:

```
Login complete. Token saved to keychain (expires: ...).
```

### 3. Use the CLI

```bash
go run ./cmd/tendies              # all timeframes
go run ./cmd/tendies --day        # today only
go run ./cmd/tendies --week       # this week
go run ./cmd/tendies --month      # this month
go run ./cmd/tendies --year       # year-to-date
go run ./cmd/tendies accounts     # list accounts
```

---

## CLI Setup — Direct Mode (no backend needed)

Edit `~/.tendies/config.json`:

```json
{
  "client_id": "YOUR_SCHWAB_CLIENT_ID",
  "client_secret": "YOUR_SCHWAB_CLIENT_SECRET",
  "redirect_url": "https://127.0.0.1:8443/callback"
}
```

```bash
go run ./cmd/tendies login --direct
go run ./cmd/tendies --day --direct
go run ./cmd/tendies accounts --direct
```

In direct mode, login prints a URL to open manually — paste the callback URL or code back into the terminal.

---

## Commands

| Command | Description |
|---|---|
| `tendies` | Calculate and print realized P&L |
| `tendies login` | Authenticate with Schwab and store OAuth token in keychain |
| `tendies accounts` | List accounts (number/hash/name/selected) |
| `tendies completion` | Generate shell completion scripts |

## Flags

| Flag | Scope | Description |
|------|-------|-------------|
| `--day` | root | Show today's P&L |
| `--week` | root | Show this week's P&L |
| `--month` | root | Show this month's P&L |
| `--year` | root | Show year-to-date P&L |
| `--symbol=HD,MU` | root | Filter by symbol/underlying |
| `--account=HASH` | root | Use specific account hash or account number |
| `--debug` | root | Verbose lot-matching output |
| `--direct` | all | Use direct Schwab API instead of broker |
| `--config` | root | Initialize/show configuration |
| `--refresh-details` | accounts | Force refresh of cached account names |

---

## Debug Mode

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

> **Warning:** Debug mode may expose sensitive trading details in terminal logs/history.

## Calculation Limitations
- Results may be inaccurate in some cases due to Schwab API limitations and data-model gaps.
- This version is best used for **daily** or other short timeframes.
- Longer historical windows may be less reliable.
- **Wash sales are not modeled** in this version.
- **Tax-lot optimization / broker tax adjustments are not modeled** in this version.

---

## Running Tests

```bash
# Go tests
go test ./...

# Go vet
go vet ./...

# Laravel tests
cd backend && php artisan test
```

---

## Troubleshooting

**"broker_url not set in config"**
Edit `~/.tendies/config.json` and set `broker_url` to your backend URL (e.g., `http://localhost:8000`).

**"no broker token in keychain"**
Run `go run ./cmd/tendies login` first.

**"missing Schwab credentials in config"** (direct mode)
Set `client_id` and `client_secret` in `~/.tendies/config.json`.

**OAuth state mismatch**
Caused by browser back/forward during auth or expired session. Retry the login.

**Keychain permission errors (macOS)**
The CLI uses the system keychain to store tokens. Grant access when prompted, or check System Settings > Privacy & Security.

**Backend returns 401 on `/api/v1/*`**
Your Passport token may be expired. Run `tendies login` again.

**Schwab token refresh failed**
Schwab refresh tokens expire after 7 days. Re-run `tendies login` to get fresh tokens.

---

## Security
- OAuth token is stored in system keychain
- Schwab `client_secret` never leaves the backend
- Schwab tokens encrypted at rest via Laravel's `encrypt()`
- CLI is a public OAuth client (no embedded secrets), protected by PKCE
