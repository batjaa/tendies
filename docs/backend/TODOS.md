# Backend TODOs

Deferred work from the architecture review (2026-03-11). Each item was considered in scope but deferred to keep the initial implementation focused.

---

## 1. Profile Endpoints

**What:** `GET /api/v1/profile` and `PATCH /api/v1/profile` — return/update user info (name, email, tier, is_anonymous, trial_ends_at).

**Why:** The web app needs a way to display and edit user profile. CLI `tendies account` could also use this to show current user info.

**Context:** The `User` model will already have `tier()`, `isAnonymous()` after Phase 1. The endpoints are thin controllers — mostly serialization. `PATCH` should only allow name changes initially; email changes need verification flow (see TODO #3).

**Depends on:** Phase 1 (TradingAccount model).

---

## 2. Account CRUD (Rename / Unlink / Set-Primary via API)

**What:** `PATCH /api/v1/accounts/{id}` (rename display_name), `DELETE /api/v1/accounts/{id}` (unlink + delete SchwabToken), `POST /api/v1/accounts/{id}/set-primary`.

**Why:** Web app users need to manage their linked trading accounts. CLI handles `set-default` locally via config, but unlink/rename need server-side support.

**Context:** `TradingAccount` model and relationships will exist after Phase 1. Unlink should cascade-delete the associated `SchwabToken` and `trading_account_hashes` rows. If the deleted account was primary, either auto-promote the next one or require the user to choose. Reject unlink if it's the user's only account (they'd be locked out of data).

**Depends on:** Phase 1 (TradingAccount model).

---

## 3. Password Reset Flow

**What:** Standard forgot-password flow — `POST /api/auth/forgot-password` sends reset email, `POST /api/auth/reset-password` with token sets new password.

**Why:** Registered users (who went through `account create` or waitlist) need a way to recover access. Without this, a forgotten password means permanent lockout from the registered account (they can still use anonymous CLI via Schwab OAuth, but lose their subscription/linked accounts).

**Context:** Laravel has built-in `Password::sendResetLink()` and `Password::reset()`. The `password_reset_tokens` migration already exists. Main work is the controller + email template. Consider whether CLI users need this (probably web-only initially).

**Depends on:** Phase 2 (auth endpoints — users need passwords first).

---

## 4. Email Delivery Monitoring for Invites

**What:** Detect and surface failed invite email deliveries so the admin can re-send.

**Why:** The waitlist flow sets invite status to `invited` when the Nova bulk action fires. If Postmark fails to deliver, the user never gets the email but the admin thinks it was sent. Silent failure.

**Context:** Two approaches: (a) Use `Mail::queue()` so Laravel's job retry handles transient Postmark failures — failed jobs are visible in the `failed_jobs` table and Nova can surface them. (b) Postmark delivery webhooks → update a `delivery_status` column on `waitlist_entries`. Option (a) is sufficient for current scale. Option (b) is better long-term but requires a webhook endpoint + Postmark configuration.

**Depends on:** Phase 4 (waitlist system).

---

## 5. Stale Token Cleanup

**What:** Artisan command (`php artisan tokens:prune`) to delete expired Passport OAuth tokens belonging to anonymous users who never returned.

**Why:** Anonymous users who link via Schwab OAuth and never come back leave behind: a `User` row (no email), a `TradingAccount`, a `SchwabToken` (expired), and Passport OAuth tokens. Over time these accumulate. The Schwab refresh token expires after 7 days anyway, so these records are inert but waste space.

**Context:** Criteria for pruning: `users.email IS NULL` + `schwab_tokens.token_expires_at < now() - 30 days` + no Passport tokens used in 30 days. Should be safe to run as a scheduled command (`schedule:run` daily). Consider whether to delete the entire `User` + cascade, or just revoke tokens and keep the user for potential return (they'd re-auth via Schwab OAuth and get matched by account hash).

**Depends on:** Phase 1 (TradingAccount model — need the schema settled first).
