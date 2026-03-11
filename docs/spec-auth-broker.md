# Tendies Auth Broker Spec

## Goal

Make Tendies usable by end users without requiring local Schwab developer app credentials (`client_id`/`client_secret`) in the CLI.

- Move Schwab confidential-client OAuth handling to a Laravel backend.
- CLI authenticates to the backend via OAuth 2.0 Authorization Code + PKCE (Laravel Passport).
- Backend stores Schwab tokens securely per user.
- CLI retains all P&L calculation logic; backend only proxies raw Schwab data.

## Non-Goals

- No local embedded Schwab `client_secret` in distributed CLI.
- No cookie-based Schwab internal auth flow.
- No direct refresh-token management in CLI.
- No server-side P&L computation — CLI owns all P&L logic.

## High-Level Architecture

```
  tendies CLI                    Laravel Backend (Passport)          Schwab API
  ───────────                    ──────────────────────────          ──────────

  1. generate PKCE verifier
     + challenge
  2. open browser ──────────────▶ /oauth/authorize
                                  (Passport auth page)
                                  user clicks "Login with Schwab"
                                  redirect ──────────────────────▶ Schwab OAuth authorize
                                                                   user consents
                                  callback ◀─────────────────────── Schwab redirect
                                  exchange Schwab code ──────────▶ Schwab token endpoint
                                  store Schwab tokens (encrypted)
                                  create/find user
                                  issue Passport auth code
                                  redirect to localhost:PORT ──▶
  3. localhost callback ◀────────
     receive auth code
  4. POST /oauth/token ─────────▶ validate PKCE + code
                                  return Passport tokens
  5. store tokens locally

  6. GET /v1/accounts ──────────▶ proxy ─────────────────────────▶ GET /trader/v1/accounts
  7. GET /v1/transactions ──────▶ proxy ─────────────────────────▶ GET /trader/v1/.../transactions
```

### Components

1. **`tendies` CLI** (Go, repo root)
   - Auth Code + PKCE login: generates PKCE pair, starts local HTTP server, opens browser, receives auth code callback, exchanges for tokens.
   - Stores Passport access + refresh tokens locally.
   - Calls backend `/v1/accounts` and `/v1/transactions` to get raw Schwab data.
   - Runs FIFO lot matching and P&L calculation locally.

2. **Laravel Backend** (`backend/`)
   - Laravel with Passport (authorization code grant + PKCE).
   - Owns Schwab OAuth confidential client credentials.
   - Performs Schwab OAuth code exchange and token refresh.
   - Stores encrypted Schwab tokens per user.
   - Proxies account and transaction data from Schwab API.

3. **Data Store** (SQLite for dev, Postgres/MySQL for production)
   - Passport tables (oauth_clients, oauth_access_tokens, oauth_refresh_tokens, etc.)
   - `schwab_tokens` — encrypted Schwab credentials per user.

## Monorepo Structure

```
tendies/
├── backend/                         # Laravel app
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   ├── SchwabCallbackController.php # Schwab OAuth callback
│   │   │   ├── AccountController.php        # proxy /v1/accounts
│   │   │   └── TransactionController.php    # proxy /v1/transactions
│   │   ├── Models/
│   │   │   ├── User.php
│   │   │   └── SchwabToken.php
│   │   └── Services/
│   │       └── SchwabService.php            # Schwab API client
│   ├── database/migrations/
│   ├── routes/api.php
│   ├── routes/web.php
│   ├── config/schwab.php
│   └── ...
├── cmd/tendies/main.go              # CLI entry point
├── internal/
│   ├── schwab/                      # existing: direct Schwab client + P&L
│   │   ├── client.go
│   │   ├── pnl.go
│   │   └── rgl.go
│   ├── broker/                      # new: broker API client
│   │   └── client.go
│   ├── datasource/                  # new: interface abstraction
│   │   └── datasource.go
│   └── config/config.go
├── go.mod
└── spec-auth-broker.md
```

## Auth Flow: Authorization Code + PKCE with Schwab OAuth Chaining

### Step-by-step

1. User runs `tendies login`.
2. CLI generates a PKCE `code_verifier` (random 128-char string) and `code_challenge` (SHA256 hash of verifier).
3. CLI starts a temporary local HTTP server on a random available port (e.g., `http://127.0.0.1:18234`).
4. CLI opens the user's browser to:
   ```
   https://tendies.example.com/oauth/authorize?
     client_id=tendies-cli&
     redirect_uri=http://127.0.0.1:18234/callback&
     response_type=code&
     code_challenge=XXXX&
     code_challenge_method=S256&
     state=RANDOM
   ```
5. Backend serves a Passport authorization page. Since the user has no Passport session yet, the page shows "Login with Schwab" which redirects to Schwab's OAuth authorize endpoint:
   ```
   https://api.schwabapi.com/v1/oauth/authorize?
     client_id=SCHWAB_CLIENT_ID&
     redirect_uri=https://tendies.example.com/auth/schwab/callback&
     state=SESSION_STATE
   ```
6. User completes Schwab consent in the browser.
7. Schwab redirects to backend callback: `GET /auth/schwab/callback?code=SCHWAB_CODE&state=SESSION_STATE`.
8. Backend validates `state`, exchanges Schwab auth code for Schwab tokens using backend-held `client_secret`.
9. Backend encrypts and stores Schwab access + refresh tokens in `schwab_tokens` table.
10. Backend creates a User record (if new) and logs them in to a Passport session.
11. Backend completes the Passport authorization, issuing a Passport auth code and redirecting the browser to:
    ```
    http://127.0.0.1:18234/callback?code=PASSPORT_CODE&state=RANDOM
    ```
12. CLI's local HTTP server receives the callback, validates `state`, and shuts down the server.
13. CLI exchanges the Passport auth code + `code_verifier` for tokens:
    ```
    POST https://tendies.example.com/oauth/token
    {
      "grant_type": "authorization_code",
      "client_id": "tendies-cli",
      "redirect_uri": "http://127.0.0.1:18234/callback",
      "code": "PASSPORT_CODE",
      "code_verifier": "ORIGINAL_VERIFIER"
    }
    ```
14. Backend validates PKCE, returns Passport `access_token` + `refresh_token`.
15. CLI stores Passport tokens locally (config file or keychain).

### Token Lifecycle

| Token | Issuer | Stored by | TTL | Refresh |
|---|---|---|---|---|
| Passport access token | Laravel | CLI (local config) | 1 hour | Via Passport refresh token |
| Passport refresh token | Laravel | CLI (local config) | 30 days | Rotated on use |
| Schwab access token | Schwab | Backend (encrypted) | 30 min | Via Schwab refresh token |
| Schwab refresh token | Schwab | Backend (encrypted) | 7 days | Auto-refreshed by backend |

## API Contracts (v1)

### 1) Passport Authorization (Passport built-in)
`GET /oauth/authorize`

Standard Passport authorization endpoint. CLI opens this in the browser with PKCE parameters. Handled entirely by Passport — no custom code needed.

### 2) Token Exchange (Passport built-in)
`POST /oauth/token`

Request (authorization code):
```json
{
  "grant_type": "authorization_code",
  "client_id": "tendies-cli",
  "redirect_uri": "http://127.0.0.1:PORT/callback",
  "code": "PASSPORT_CODE",
  "code_verifier": "ORIGINAL_VERIFIER"
}
```

Request (refresh):
```json
{
  "grant_type": "refresh_token",
  "client_id": "tendies-cli",
  "refresh_token": "def502..."
}
```

Response `200`:
```json
{
  "access_token": "eyJ...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "def502..."
}
```

### 3) Accounts
`GET /v1/accounts`

Headers: `Authorization: Bearer <passport_token>`

Response `200`:
```json
[
  {
    "account_number": "12345678",
    "account_hash": "ABCDEF...",
    "display_name": "Individual Brokerage"
  }
]
```

### 4) Transactions
`GET /v1/transactions?account_hash=X&start=2025-01-01&end=2025-01-31&types=TRADE,RECEIVE_AND_DELIVER`

Headers: `Authorization: Bearer <passport_token>`

Response `200`: Raw Schwab transaction JSON (array), passed through as-is for CLI to parse.

### 5) Health
`GET /health`

Response `200`:
```json
{
  "status": "ok"
}
```

## Data Model

### `users`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `name` | string | Display name (from Schwab or default) |
| `email` | string (unique) | Required by Passport; use Schwab account hash as placeholder |
| `password` | string | Not used for login (set to random hash) |
| `schwab_account_hash` | string (unique, nullable) | Primary identity link to Schwab |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

### `schwab_tokens`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `user_id` | bigint (FK -> users) | Unique |
| `encrypted_access_token` | text | Laravel encrypted |
| `encrypted_refresh_token` | text | Laravel encrypted |
| `token_expires_at` | timestamp | Schwab access token expiry |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

### Passport tables (managed by Passport migrations)
- `oauth_clients` — CLI client (`tendies-cli`, public client, no secret)
- `oauth_access_tokens` — Passport access tokens
- `oauth_refresh_tokens` — Passport refresh tokens
- `oauth_auth_codes` — Authorization codes (used during PKCE exchange)

## Go Interface: DataSource

```go
// internal/datasource/datasource.go
package datasource

type DataSource interface {
    GetAccountNumbers(ctx context.Context) ([]schwab.AccountNumber, error)
    GetTransactions(ctx context.Context, accountHash string,
        startDate, endDate time.Time, txnType string) ([]schwab.Transaction, error)
}
```

- `schwab.Client` satisfies this interface (direct mode, for dev).
- `broker.Client` satisfies this interface (broker mode, for end users).
- `CalculateRealizedPnL` accepts `DataSource` instead of `*schwab.Client`.

## CLI Changes

1. **Config**: Add `broker_url` (default: production endpoint).
2. **`tendies login`**: PKCE flow via `broker.Client` — generate verifier, start local server, open browser, receive callback, exchange code for tokens.
3. **`tendies accounts`**: Fetch via `DataSource.GetAccountNumbers()`.
4. **`tendies` (P&L)**: Fetch transactions via `DataSource.GetTransactions()`, compute P&L locally.
5. **Token storage**: Store Passport access + refresh tokens (replaces Schwab tokens).
6. **Direct mode**: Keep `--direct` flag for developers who have their own Schwab app credentials.

## Security Requirements

1. **Secrets**: Schwab `client_secret` stored only in backend `.env` / secret manager. Never in CLI.
2. **PKCE**: CLI is a public OAuth client (no client secret). PKCE with S256 challenge method prevents authorization code interception.
3. **Token encryption**: Schwab tokens encrypted at rest via Laravel's `encrypt()` (APP_KEY). Production: rotate to KMS envelope encryption.
4. **Transport**: TLS everywhere. HSTS on backend.
5. **Passport tokens**: Short access token TTL (1 hour). Refresh token rotation enabled.
6. **Redirect URI validation**: Only allow `http://127.0.0.1:*` redirect URIs for the CLI client (loopback only, any port).
7. **Rate limits**: Auth endpoints: 5 req/min per IP. Data endpoints: 60 req/min per user.
8. **Logging**: Structured logs with request IDs. No token values in logs.
9. **CORS**: Restrict to known origins (not needed for CLI-only API, but set restrictive defaults).

## Rollout Plan

### Milestone 1: Laravel Skeleton + Passport Auth Code + PKCE
- `laravel new backend`, install Passport.
- SQLite config, User model with `HasApiTokens`.
- Create Passport public client (`tendies-cli`, no secret, PKCE required).
- Configure allowed redirect URIs for loopback.
- Health endpoint.
- **Verify**: `php artisan serve` → browser to `/oauth/authorize?...` shows Passport auth page.

### Milestone 2: Schwab OAuth Chaining
- `config/schwab.php` with Schwab client credentials from env.
- `SchwabService`: authorize URL builder, code exchange, token refresh.
- `SchwabCallbackController`: validates state, exchanges code, stores encrypted tokens, creates user, logs user in to Passport session.
- Custom Passport authorization flow: unauthenticated users are redirected to Schwab OAuth, then returned to complete the Passport authorization.
- **Verify**: Full login flow in browser. `schwab_tokens` table has encrypted tokens. Passport issues auth code to redirect URI.

### Milestone 3: Data Proxy Endpoints
- `AccountController`: `GET /v1/accounts` (Passport auth required).
- `TransactionController`: `GET /v1/transactions` (Passport auth required).
- Both use `SchwabService` with auto-refresh to call Schwab API.
- Rate limiting middleware.
- **Verify**: `curl -H "Authorization: Bearer ..." /v1/accounts` returns account list.

### Milestone 4: CLI Broker Client + Refactor
- `internal/datasource/datasource.go` — `DataSource` interface.
- `internal/broker/client.go` — implements `DataSource`, PKCE login flow (local HTTP server, browser open, code exchange).
- Refactor `CalculateRealizedPnL` to accept `DataSource`.
- CLI login uses auth code + PKCE; P&L/accounts use broker client.
- Config: `broker_url`, Passport token storage.
- **Verify**: `tendies login` → browser opens → Schwab consent → callback → `tendies --day` shows P&L via broker.

### Milestone 5: Polish + Cleanup
- Remove `internal/schwab/rgl.go` (web scraping client).
- Remove `cmd/checktypes/` (debug utility).
- Update `.gitignore` for Laravel (`backend/vendor/`, `backend/.env`, `backend/database/database.sqlite`).
- Update `.goreleaser.yaml` (exclude `backend/`).
- Update `README.md` with new setup instructions.
- **Verify**: `go build ./cmd/tendies` succeeds. `go vet ./...` clean.

## Risks

- Schwab API rate limits or behavior changes affect the backend centrally (all users impacted).
- Multi-user token storage introduces compliance burden (PII, financial data).
- Backend outage blocks all login and data access.
- Schwab refresh tokens expire after 7 days — if user is inactive longer, they must re-login.
- Local HTTP server for callback won't work in headless/SSH environments (acceptable trade-off for simpler UX).

## Open Questions (Resolved)

| Question | Decision |
|---|---|
| User identity source? | Created automatically via Schwab OAuth callback. No email/password. |
| Auth grant type? | Authorization Code + PKCE (public client, no client secret in CLI). |
| Multi-account? | One Schwab login per Tendies user. Multiple Schwab accounts under one login supported. |
| Backend DB? | SQLite for dev, Postgres for production. |
| P&L on server or client? | Client (CLI). Backend only proxies raw transaction data. |
| Backwards compat? | Not needed. Direct mode kept as `--direct` for dev convenience only. |
