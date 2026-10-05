# Tendies

> Realized Schwab P&L in your terminal.

[![Go Version](https://img.shields.io/badge/go-1.25.7%2B-00ADD8?logo=go)](https://go.dev/)
[![Platform](https://img.shields.io/badge/platform-macOS-lightgrey)](#requirements)
[![License](https://img.shields.io/badge/license-private-informational)](#)

Tendies is a CLI that computes realized gains/losses from Schwab transactions using FIFO lot matching. It supports day, week, month, and year-to-date views, account selection, symbol filtering, and detailed debug output.

Two modes:

- **Broker mode** (default) — CLI authenticates via a hosted backend. No Schwab developer credentials needed.
- **Direct mode** (`--direct`) — CLI talks to the Schwab API directly. Requires your own Schwab app.

---

## Broker Mode (Default)

This is the simplest way to use Tendies. You don't need a Schwab developer account.

### Install

```bash
brew tap batjaa/tools
brew install tendies
```

### Configure

```bash
tendies --config
```

Edit `~/.tendies/config.json` with the broker client ID you were given:

```json
{
  "broker_client_id": "YOUR_BROKER_CLIENT_ID"
}
```

> The broker URL defaults to `https://mytendies.app`. Override with `broker_url` in the config if needed.

### Log in

```bash
tendies account link
```

This opens your browser for OAuth login. After authorizing, you're redirected back and the token is saved to your macOS keychain.

### Check your P&L

```bash
tendies                # all timeframes (day, week, month, year)
tendies --day          # today only
tendies --week         # this week
tendies --month        # this month
tendies --year         # year-to-date
```

Filter by symbol or account:

```bash
tendies --day --symbol=NVDA,TSLA
tendies --account=HASH_OR_NUMBER
```

### List accounts

```bash
tendies account list
```

---

## Direct Mode

Direct mode talks to the Schwab API without a broker backend. You need your own Schwab developer app credentials from [developer.schwab.com](https://developer.schwab.com).

### Configure

```bash
tendies --config
```

Edit `~/.tendies/config.json`:

```json
{
  "client_id": "YOUR_SCHWAB_CLIENT_ID",
  "client_secret": "YOUR_SCHWAB_CLIENT_SECRET",
  "redirect_url": "https://127.0.0.1:8443/callback"
}
```

### Log in

```bash
tendies account link --direct
```

This prints a URL to open in your browser. After authorizing with Schwab, paste the full callback URL back into the terminal. The token is saved to your macOS keychain.

> Schwab refresh tokens expire after 7 days. Re-run `tendies account link --direct` when they expire.

### Usage

All commands accept the `--direct` flag:

```bash
tendies --direct              # all timeframes
tendies --direct --day        # today only
tendies --direct --symbol=HD
tendies --direct --account=HASH_OR_NUMBER
tendies account list --direct
tendies account list --direct --refresh-details   # force refresh cached names
```

---

## Commands & Flags

| Command | Description |
|---|---|
| `tendies` | Calculate and print realized P&L |
| `tendies account link` | Connect or re-authorize Schwab (browser OAuth, finishes automatically) |
| `tendies account create` | Create a Tendies account |
| `tendies account login` | Log in with email/password (`--direct` uses Schwab OAuth) |
| `tendies account status` | Show account and subscription status |
| `tendies account logout` | Remove saved token from keychain |
| `tendies account list` | List accounts (number/hash/name/selected) |
| `tendies version` | Print version |

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

`tendies auth login` and `tendies auth logout` remain compatible aliases for
`account link` and `account logout`. Direct login accepts the full callback URL,
validates its state and redirect address, and stores tokens in the system keychain.
Broker refresh-token rotations are saved before subsequent API requests continue.

### Debug Mode

`--debug` prints timeframe boundaries, per-account summaries, calculation warnings, and closed trades with matched opening lots.

> **Warning:** Debug mode may expose sensitive trading details in terminal logs/history.

### Calculation Limitations

- Best used for **daily** or short timeframes; longer windows may be less reliable.
- **Wash sales** are not modeled.
- **Tax-lot optimization / broker tax adjustments** are not modeled.
- The engine uses **FIFO**, regardless of the account's Schwab lot-selection setting.
  High Cost, LIFO, Tax Lot Optimizer, and specific-lot sales can produce different results.
- Missing expiration data fails the calculation rather than silently omitting it.
- Results may differ from Schwab due to missing opening history, transferred positions,
  corporate actions, option assignment adjustments, and per-lot rounding.
- Schwab's Realized Gain/Loss report is the reconciliation reference. Matching its
  default method alone does not reproduce historical lot overrides or adjusted cost basis.
  The authenticated website exposes the method, but this project does not automatically
  discover it through the public Trader API or use private browser-session endpoints.

---

## Local Development

### Architecture

```
tendies/
├── cmd/tendies/         # CLI entry point (Cobra)
├── internal/
│   ├── broker/          # Broker API client (OAuth PKCE + REST)
│   ├── schwab/          # Direct Schwab API client + FIFO P&L engine
│   └── config/          # Config file + macOS keychain token storage
├── backend/             # Laravel backend (Passport OAuth + Schwab proxy)
├── Makefile             # Local build with version injection
└── .goreleaser.yaml     # Release automation (cross-platform + Homebrew)
```

### Prerequisites

- Go 1.25.7+
- PHP 8.2+ and Composer (for backend)
- A Schwab developer app at [developer.schwab.com](https://developer.schwab.com)

### Build the CLI

```bash
make build      # ./tendies binary with version from git tag
make install    # install to $GOPATH/bin
make test       # go test ./...
```

### Backend Setup

The Laravel backend acts as an OAuth proxy between the CLI and the Schwab API.

```bash
cd backend
cp auth.json.example auth.json   # Nova license credentials (required for composer install)
composer install
cp .env.example .env
php artisan key:generate
```

> **Nova:** You need a valid `auth.json` with your Nova license credentials. Copy `auth.json.example` and fill in your details. See the [Nova setup skill](/docs/backend/IMPLEMENTATION_PLAN.md) for more info.

Edit `backend/.env` with your Schwab credentials:

```env
SCHWAB_CLIENT_ID=your-schwab-client-id
SCHWAB_CLIENT_SECRET=your-schwab-client-secret
SCHWAB_REDIRECT_URI=http://localhost:8000/auth/schwab/callback
```

Run migrations and create the Passport client:

```bash
php artisan migrate
php artisan passport:client --public --name="tendies-cli"
```

Note the **Client ID** output — you'll need it for the CLI config.

Start the server:

```bash
php artisan serve   # http://localhost:8000
```

### Wire CLI to Local Backend

```bash
tendies --config
```

Edit `~/.tendies/config.json` (override the default broker URL to point at your local server):

```json
{
  "broker_url": "http://localhost:8000",
  "broker_client_id": "PASTE_PASSPORT_CLIENT_ID_HERE"
}
```

### E2E Flow

1. Start the backend: `cd backend && php artisan serve`
2. Log in: `tendies account link` — opens browser, completes OAuth, saves token to keychain
   The CLI waits for that authorization attempt to finish automatically; no Enter is needed. Complete the browser flow within 10 minutes, or press Ctrl+C to cancel and retry.
3. Fetch P&L: `tendies --day`

For direct mode (no backend needed):

1. Configure Schwab credentials in `~/.tendies/config.json`
2. Log in: `tendies account link --direct` — paste callback URL
3. Fetch P&L: `tendies --direct --day`

### Backend API Routes

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/health` | No | Health check |
| GET | `/api/v1/accounts` | Passport | List Schwab accounts |
| GET | `/api/v1/transactions` | Passport | Get transactions (cached) |
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

### Release

See [RELEASE.md](docs/RELEASE.md) for the full release process. Short version:

```bash
git tag v0.1.0
git push origin main v0.1.0
```

GitHub Actions runs GoReleaser to build cross-platform binaries and update the Homebrew tap.

---

## Troubleshooting

**"broker_client_id not set in config"**
Set `broker_client_id` in `~/.tendies/config.json`, or use `--direct` for direct Schwab API access.

**"missing Schwab credentials in config"**
Set `client_id` and `client_secret` in `~/.tendies/config.json` (direct mode only).

**"no broker token in keychain"** / **"no OAuth token in keychain"**
Run `tendies account link` (broker) or `tendies account link --direct` (direct mode).

**OAuth state mismatch**
Retry the login — caused by browser back/forward during auth or an expired session.

**Keychain permission errors (macOS)**
Grant access when prompted, or check System Settings > Privacy & Security.

**Schwab token refresh failed**
Schwab refresh tokens expire after 7 days. Re-run `tendies account link` or `tendies account link --direct`.

## Production on Coolify

The root `Dockerfile` builds the Laravel backend with PHP 8.4 and Node 22.
`compose.yml` runs the web app, one database queue worker, Nightwatch, and MySQL 8.
CLI releases continue to use GoReleaser independently.

Set production environment variables in Coolify, including the existing application
key, Schwab OAuth settings, Stripe settings, Passport keys, and database credentials.
Supply Nova Composer authentication as the `COMPOSER_AUTH` build secret, with runtime
exposure disabled. Database passwords must be available during Compose interpolation.
Persist both named volumes; preserve `storage/oauth-private.key` and
`storage/oauth-public.key` when migrating so existing CLI tokens keep working.

Workers and migrations default to disabled for migration verification. After importing
the database and storage, set `QUEUE_WORKER_ENABLED=true` and `RUN_MIGRATIONS=true`.
Set Coolify's application stop grace period to 960 seconds. The `/up` endpoint checks
application health. TLS terminates at the home ingress and Coolify proxy; the backend
already trusts forwarded proxy headers.

## Production database maintenance

The Coolify Compose stack uses MySQL 8.4.11. Database volumes have versioned names so the pre-upgrade volumes can be retained for recovery. Back up and rehearse a restore before changing database versions; stop app writers and drain workers before the final copy.

Retained old volumes are migration snapshots, not replicas. Reconcile writes made after the upgrade before any rollback; never start an older database image against an upgraded data directory.
