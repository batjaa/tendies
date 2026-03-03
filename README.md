# Tendies

> Realized Schwab P&L in your terminal.

[![Go Version](https://img.shields.io/badge/go-1.25.7%2B-00ADD8?logo=go)](https://go.dev/)
[![Platform](https://img.shields.io/badge/platform-macOS-lightgrey)](#requirements)
[![License](https://img.shields.io/badge/license-private-informational)](#)

Tendies is a CLI that computes realized gains/losses from Schwab transactions using FIFO lot matching. It supports day, week, month, and year-to-date views, account selection, symbol filtering, and detailed debug output.

## Getting Started

### Install

```bash
brew tap batjaa/tools
brew install tendies
```

Or build from source:

```bash
go install github.com/batjaa/tendies/cmd/tendies@latest
```

### Configure

```bash
tendies --config
```

This creates `~/.tendies/config.json`. Edit it with your Schwab credentials (register at [developer.schwab.com](https://developer.schwab.com)):

```json
{
  "client_id": "YOUR_SCHWAB_CLIENT_ID",
  "client_secret": "YOUR_SCHWAB_CLIENT_SECRET",
  "redirect_url": "https://127.0.0.1:8443/callback"
}
```

### Log in

```bash
tendies login --direct
```

This prints a URL to open in your browser. After authorizing with Schwab, paste the callback URL back into the terminal. The token is saved to your macOS keychain.

> Schwab refresh tokens expire after 7 days. Re-run `tendies login --direct` when they expire.

### Check your P&L

```bash
tendies --direct              # all timeframes
tendies --direct --day        # today only
tendies --direct --week       # this week
tendies --direct --month      # this month
tendies --direct --year       # year-to-date
```

Filter by symbol or account:

```bash
tendies --direct --day --symbol=NVDA,TSLA
tendies --direct --account=HASH_OR_NUMBER
```

### List accounts

```bash
tendies accounts --direct
tendies accounts --direct --refresh-details   # force refresh cached names
```

---

## Commands & Flags

| Command | Description |
|---|---|
| `tendies` | Calculate and print realized P&L |
| `tendies login` | Authenticate with Schwab via OAuth |
| `tendies accounts` | List accounts (number/hash/name/selected) |
| `tendies completion` | Generate shell completion scripts |

| Flag | Scope | Description |
|------|-------|-------------|
| `--day` | root | Today's P&L |
| `--week` | root | This week's P&L |
| `--month` | root | This month's P&L |
| `--year` | root | Year-to-date P&L |
| `--symbol=HD,MU` | root | Filter by symbol/underlying |
| `--account=HASH` | root | Use specific account hash or number |
| `--debug` | root | Verbose lot-matching output |
| `--direct` | all | Use direct Schwab API instead of broker |
| `--config` | root | Initialize/show configuration |
| `--refresh-details` | accounts | Force refresh cached account names |

### Debug Mode

`--debug` prints timeframe boundaries, per-account summaries, calculation warnings, and closed trades with matched opening lots.

> **Warning:** Debug mode may expose sensitive trading details in terminal logs/history.

### Calculation Limitations

- Best used for **daily** or short timeframes; longer windows may be less reliable.
- **Wash sales** are not modeled.
- **Tax-lot optimization / broker tax adjustments** are not modeled.
- Results may differ from Schwab due to API limitations and data-model gaps.

---

## Development

### Architecture

```
tendies/
├── cmd/tendies/         # CLI entry point (Cobra)
├── internal/
│   ├── broker/          # Broker API client (talks to Laravel backend)
│   ├── schwab/          # Direct Schwab API client + P&L engine
│   └── config/          # CLI config + keychain token storage
├── backend/             # Laravel API (Passport OAuth + Schwab proxy)
└── .goreleaser.yaml     # Release automation
```

The CLI has two modes:

- **Broker mode** (default) — CLI authenticates via the Laravel backend, which proxies Schwab API calls. Users don't need their own Schwab developer credentials.
- **Direct mode** (`--direct`) — CLI talks to Schwab API directly. Requires your own Schwab app credentials.

### Backend Setup (Broker Mode)

The backend is only needed for broker mode. Direct mode users can skip this.

```bash
cd backend
composer install
cp .env.example .env
```

Edit `backend/.env` with your Schwab credentials:

```
SCHWAB_CLIENT_ID=your-schwab-client-id
SCHWAB_CLIENT_SECRET=your-schwab-client-secret
SCHWAB_REDIRECT_URI=http://localhost:8000/auth/schwab/callback
```

Run migrations and create the Passport client:

```bash
php artisan migrate
php artisan passport:client --public --name="tendies-cli"
```

Note the **Client ID** — you'll need it for the CLI config.

Start the server:

```bash
php artisan serve   # http://localhost:8000
```

Configure the CLI for broker mode in `~/.tendies/config.json`:

```json
{
  "broker_url": "http://localhost:8000",
  "broker_client_id": "PASTE_PASSPORT_CLIENT_ID_HERE"
}
```

Then log in (without `--direct`):

```bash
tendies login
```

#### Backend API Routes

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/health` | No | Health check |
| GET | `/api/v1/accounts` | Passport | List Schwab accounts |
| GET | `/api/v1/transactions` | Passport | Get transactions |
| GET | `/oauth/authorize` | Session | Passport authorization |
| POST | `/oauth/token` | No | Exchange auth code for tokens |

### Running Tests

```bash
# Go
go test ./...
go vet ./...

# Laravel
cd backend && php artisan test
```

### Troubleshooting

**"missing Schwab credentials in config"**
Set `client_id` and `client_secret` in `~/.tendies/config.json`.

**"broker_url not set in config"**
Set `broker_url` in `~/.tendies/config.json` (broker mode only).

**"no broker token in keychain"**
Run `tendies login` first.

**OAuth state mismatch**
Retry the login — caused by browser back/forward during auth or expired session.

**Keychain permission errors (macOS)**
Grant access when prompted, or check System Settings > Privacy & Security.

**Schwab token refresh failed**
Schwab refresh tokens expire after 7 days. Re-run `tendies login`.
