# Backend TODOs

Deferred work from the architecture review (2026-03-11). Each item was considered in scope but deferred to keep the initial implementation focused.

---

## 1. ~~Profile Endpoints~~ ✅

Done. `ProfileController` with `GET /api/v1/profile` + `PATCH /api/v1/profile` (name-only updates). `/api/v1/me` forwards to the same controller for backward compat.

---

## 2. ~~Account CRUD (Rename / Unlink / Set-Primary via API)~~ ✅

Done. `TradingAccountController` with list, rename, unlink (rejects last account, auto-promotes primary), and set-primary. Routes under `/api/v1/trading-accounts/`.

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

---

## 6. Wire Rate Limiting & Timeframe Middleware to Routes

**What:** Apply `rate-limit-cli` and `restrict-timeframe` middleware to the transaction/account API routes. Replace `EnsureSubscribed` middleware — free users can query, just rate-limited.

**Why:** The middleware exists and is aliased in `bootstrap/app.php`, but is not applied to any routes in `routes/api.php`. Free users currently hit `EnsureSubscribed` and get blocked entirely instead of being rate-limited.

**Context:** Per the implementation plan (Phase 3.3), the route group should change from `['auth:api', 'subscribed']` to `['auth:api', 'rate-limit-cli', 'restrict-timeframe']`. Delete `EnsureSubscribed` middleware after switching. CLI already sends `X-Query-ID` and `X-Timezone` headers.

**Depends on:** Phase 2 (auth endpoints working).
