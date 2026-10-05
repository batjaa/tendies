# Tendies Backend

Laravel backend for the Tendies CLI. Acts as an OAuth proxy for the Schwab API, manages user accounts, and handles rate limiting.

## Setup

### Requirements

- PHP 8.2+
- Composer
- MySQL (staging/prod) or SQLite (local)

### Install

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### Passport OAuth Clients

Two Passport clients are required:

```bash
# 1. Public client — used for PKCE authorization code flow (account link via browser)
php artisan passport:client --public --name="tendies-cli"

# 2. Personal access client — used for PAT issuance (account create/login/upgrade)
php artisan passport:client --personal --name="Tendies CLI Personal Access"
```

Both must exist on every environment (local, staging, production).

### Admin Settings

Site settings (e.g., waitlist mode) are managed via Nova at `/nova/nova-settings`. Access requires a `@mytendies.app` email. Settings are stored in the `nova_settings` table and seeded by migrations.

### Environment Variables

See `.env.example` for all required variables. Key ones:

- `SCHWAB_CLIENT_ID` / `SCHWAB_CLIENT_SECRET` — Schwab API credentials
- `SCHWAB_REDIRECT_URL` — OAuth callback URL
- `POSTMARK_API_KEY` — Postmark transactional email API key

## Testing

### Unit & Feature Tests

```bash
php artisan test
```

### E2E Tests (Playwright)

```bash
npx playwright install chromium   # first time only
npx playwright test
```

Playwright uses `.env.e2e` (SQLite) and starts a local server on port 8899 automatically. See `playwright.config.ts` for details.

## Deployment

Production runs on Coolify using the repository's Docker Compose stack and the
`main` branch. The app, queue worker, and Nightwatch use the same source revision.
See [Production on Coolify](../README.md#production-on-coolify) for configuration.

- **Production:** `https://mytendies.app`
- Verify the Coolify deployment has finished and `/up` and `/api/health` succeed
  after pushing. The legacy Forge checkout is not the current production target.

## Existing accounts and waitlist registration

Waitlist registration never deletes an existing account to reuse its email address.
Users with an existing email should log in or use password reset, including legacy
waitlist users. Having no Passport tokens does not mean a user is abandoned: web-only
users may never create API tokens. Brokerage linking only permits claiming anonymous
users; registered accounts retain ownership.
