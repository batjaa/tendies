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

### Environment Variables

See `.env.example` for all required variables. Key ones:

- `SCHWAB_CLIENT_ID` / `SCHWAB_CLIENT_SECRET` — Schwab API credentials
- `SCHWAB_REDIRECT_URL` — OAuth callback URL

## Deployment

Deployed via Laravel Forge. Push to `staging` or `main` branch triggers auto-deploy.

- **Staging:** `staging.mytendies.app`
- **Production:** `mytendies.app`
