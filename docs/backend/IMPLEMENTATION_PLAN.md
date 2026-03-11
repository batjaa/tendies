# Backend Implementation Plan

## Context

This plan covers the backend changes to support multi-provider accounts, waitlist-based onboarding, freemium rate limiting, and the new CLI auth model. See [ARCHITECTURE_PLAN.md](ARCHITECTURE_PLAN.md) for the full design context. See [TODOS.md](TODOS.md) for deferred work.

### Key Decisions

- `TradingAccount` = one per provider connection (one Schwab OAuth login), not per brokerage account
- `users.email` nullable — anonymous CLI users have no email
- CLI `account create`/`login` happen in the terminal via direct API calls (personal access tokens, 90-day expiry)
- CLI `account link` uses browser PKCE flow (Schwab OAuth requires browser)
- Authenticated linking uses `/api/v1/link/initiate` → session ID → browser OAuth (token never in URL)
- Anonymous users who `account create`/`login` get old PKCE tokens revoked, replaced with PAT
- Free tier: 1 provider connection, 15 queries/day, day & week only
- Pro tier: unlimited connections, unlimited queries, all timeframes
- Waitlist: simple signup → manual Nova approve → invite email → Schwab OAuth → trial
- Token refresh race: cache lock around `SchwabService::refreshToken()`
- Account identity: normalized `trading_account_hashes` pivot table (not JSON column)
- Account claiming: `SELECT ... FOR UPDATE` to prevent race conditions
- Billing: Stripe/Cashier owns billing info, no separate billing columns
- Rate limit day boundary: user timezone via `X-Timezone` header

### Architecture Decisions (from review)

```
┌─────────────────────────────────────────────────────────┐
│              POST-MIGRATION AUTH FLOW                    │
│                                                         │
│  CLI                    Backend              Schwab      │
│  ───                    ───────              ──────      │
│                                                         │
│  account create ──POST /auth/register──→ User created   │
│                  ←── PAT (90-day) ─────┘                │
│                                                         │
│  account login ───POST /auth/login────→ Auth::attempt   │
│                  ←── PAT (90-day) ─────┘  revoke old    │
│                                           tokens        │
│                                                         │
│  account upgrade ─POST /v1/account/upgrade─→ anon User  │
│  (has token)      ←── PAT (90-day) ────────┘ upgraded   │
│                                               + revoke  │
│                                                         │
│  account link ───POST /v1/link/initiate→ link_session   │
│  (authed)        ←── session_id ───────┘                │
│                  ──browser /auth/link/{id}──→ Schwab──→ │
│                                    callback ←──code───┘ │
│                  ←── redirect to CLI ──────┘            │
│                       (success, no new token)           │
│                                                         │
│  account link ───browser /oauth/authorize──→            │
│  (anon)                    redirectGuestsTo──→ Schwab──→ │
│                                    callback ←──code───┘ │
│                  ←── PKCE token via Passport ────────┘  │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

---

## Phase 1: Data Model Migration

**Goal:** Introduce `TradingAccount` with normalized hash lookup, migrate `SchwabToken` from user-linked to account-linked, make email nullable, add token refresh lock.

### 1.1 Migration: `create_trading_accounts_table`

```sql
CREATE TABLE trading_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(255) NOT NULL DEFAULT 'schwab',
    display_name VARCHAR(255) NULL,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    last_synced_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX (user_id),
    INDEX (provider)
);
```

### 1.2 Migration: `create_trading_account_hashes_table`

Normalized pivot table for fast dedup lookups (avoids JSON column querying).

```sql
CREATE TABLE trading_account_hashes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trading_account_id BIGINT UNSIGNED NOT NULL,
    hash_value VARCHAR(255) NOT NULL,

    FOREIGN KEY (trading_account_id) REFERENCES trading_accounts(id) ON DELETE CASCADE,
    UNIQUE INDEX (hash_value),
    INDEX (trading_account_id)
);
```

**Notes:**
- `hash_value` is a single Schwab `hashValue` string
- Unique on `hash_value` — one Schwab brokerage account can only belong to one TradingAccount
- Dedup during linking: `WHERE hash_value IN (...incoming hashes...)` — indexed, instant
- `display_name` defaults to Schwab account type + last 4 digits

### 1.3 Migration: `modify_schwab_tokens_for_trading_accounts`

```sql
ALTER TABLE schwab_tokens ADD COLUMN trading_account_id BIGINT UNSIGNED NULL AFTER id;
-- Backfill runs here (see data migration below)
ALTER TABLE schwab_tokens DROP FOREIGN KEY schwab_tokens_user_id_foreign;
ALTER TABLE schwab_tokens DROP INDEX schwab_tokens_user_id_unique;
ALTER TABLE schwab_tokens DROP COLUMN user_id;
ALTER TABLE schwab_tokens ADD UNIQUE INDEX (trading_account_id);
ALTER TABLE schwab_tokens ADD FOREIGN KEY (trading_account_id) REFERENCES trading_accounts(id) ON DELETE CASCADE;
```

**Data migration (in the migration file):**
1. For each existing `schwab_token`, create a `TradingAccount` with `user_id` from the token and `provider = 'schwab'`
2. Create `trading_account_hashes` rows (split composite hash back into individual hashes if possible, or store composite as single hash for existing data)
3. Set `trading_account_id` on the schwab_token
4. Set `is_primary = true` (only one account per user at this point)

### 1.4 Migration: `make_user_email_nullable`

```sql
ALTER TABLE users MODIFY email VARCHAR(255) NULL;
-- MySQL/SQLite unique indexes already allow multiple NULLs
```

### 1.5 Model Changes

**TradingAccount (new):**

```
  ┌──────────┐     ┌────────────────┐     ┌─────────────┐
  │   User   │────<│ TradingAccount │────┤│ SchwabToken  │
  │          │ 1:N │                │ 1:1 │              │
  │ email?   │     │ provider       │     │ access_token │
  │ password?│     │ display_name   │     │ refresh_token│
  │ tier()   │     │ is_primary     │     │ expires_at   │
  └──────────┘     │                │     └──────────────┘
                   │                │────<┌──────────────────────┐
                   │                │ 1:N │ TradingAccountHash   │
                   └────────────────┘     │ hash_value (unique)  │
                                          └──────────────────────┘
```

```php
class TradingAccount extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'display_name',
        'is_primary', 'last_synced_at',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function schwabToken(): HasOne { return $this->hasOne(SchwabToken::class); }
    public function hashes(): HasMany { return $this->hasMany(TradingAccountHash::class); }
}
```

**TradingAccountHash (new):**
```php
class TradingAccountHash extends Model
{
    public $timestamps = false;

    protected $fillable = ['trading_account_id', 'hash_value'];

    public function tradingAccount(): BelongsTo { return $this->belongsTo(TradingAccount::class); }
}
```

**User model updates:**
```php
// Remove: schwabToken() relationship
// Remove: schwab_account_hash from fillable
// Add:

const FREE_DAILY_LIMIT = 15;
const FREE_MAX_DAYS = 7;

public function tradingAccounts(): HasMany { return $this->hasMany(TradingAccount::class); }

public function primaryTradingAccount(): HasOne
{
    return $this->hasOne(TradingAccount::class)->where('is_primary', true);
}

public function tier(): string
{
    if ($this->subscribed('default')) return 'pro';
    if ($this->onGenericTrial()) return 'trial';
    return 'free';
}

public function isAnonymous(): bool
{
    return $this->email === null;
}

public function canLinkMoreAccounts(): bool
{
    return $this->tier() !== 'free' || $this->tradingAccounts()->count() === 0;
}
```

**SchwabToken model updates:**
```php
// Change: user_id → trading_account_id in fillable
// Change: user() → tradingAccount() belongsTo TradingAccount
```

### 1.6 Update SchwabService

- `storeTokens()`: accept `TradingAccount` instead of `User`
- `getValidAccessToken()`: accept `TradingAccount`
- `makeRequest()`: accept `TradingAccount`
- `fetchAccountHashes()` (renamed from `fetchAccountHash`): return `array` of hashValues instead of composite hash
- Add cache lock around token refresh:

```php
// In getValidAccessToken(TradingAccount $tradingAccount)
//
// Lock flow:
//   Request A arrives, token expired → acquires lock → refreshes → releases
//   Request B arrives during refresh → blocks on lock → re-reads token → already fresh
//
$lock = Cache::lock("schwab_refresh:{$tradingAccount->id}", 10);
try {
    $lock->block(5);
    $tradingAccount->schwabToken->refresh(); // re-read from DB
    if ($tradingAccount->schwabToken->token_expires_at->isFuture()) {
        return $tradingAccount->schwabToken->encrypted_access_token;
    }
    return $this->doRefresh($tradingAccount);
} finally {
    $lock->release();
}
```

### 1.7 Extract LinkAccountService

Extract callback business logic from `SchwabCallbackController` into a dedicated service. Controller validates input and delegates.

```php
class LinkAccountService
{
    /**
     * Resolve or create a TradingAccount from Schwab OAuth callback data.
     *
     * Decision tree:
     *
     *   incoming hashes ──→ match existing TradingAccount?
     *     │                    │
     *     │ YES                │ NO
     *     │                    │
     *     ├─ same user?        ├─ link session exists?
     *     │  → refresh tokens  │  → create TradingAccount under session's user
     *     │                    │
     *     ├─ anon user?        ├─ waitlist invite in session?
     *     │  → claim (FOR      │  → create User (with email) + TradingAccount + trial
     *     │    UPDATE lock)    │
     *     │                    └─ neither?
     *     └─ other registered?    → create anonymous User + TradingAccount
     *        → reject 409
     */
    public function resolveOrCreateAccount(
        array $hashes,
        array $tokenData,
        ?User $authenticatedUser = null,
        ?string $inviteToken = null,
    ): TradingAccount;
}
```

### 1.8 Update Controllers

**SchwabCallbackController:** Slim down to validation + delegation to `LinkAccountService`.

**TransactionController / AccountController:**
- Resolve `TradingAccount` from request (by account hash or user's primary)
- Pass `TradingAccount` to `SchwabService` instead of `User`

---

## Phase 2: CLI Auth Endpoints

**Goal:** Support `tendies account create`, `tendies account login`, `tendies account link` (authenticated), `tendies account upgrade`.

### 2.1 Registration Endpoint

```
POST /api/auth/register
Body: { name, email, password, password_confirmation }
No auth required.
```

**Logic:**
1. Validate input (name required, email unique, password min 8)
2. Create new user with email/name/password
3. Issue 90-day personal access token
4. Return `{ token, user: { id, name, email, tier } }`

### 2.2 Account Upgrade Endpoint

```
POST /api/v1/account/upgrade
Header: Authorization: Bearer {token}
Body: { name, email, password, password_confirmation }
```

**Logic:**
1. Validate: user must be anonymous (`isAnonymous()`)
2. Validate: email unique, password min 8
3. Set name, email, hashed password on existing user
4. Revoke all existing Passport OAuth tokens (`$user->tokens()->delete()`)
5. Issue 90-day personal access token
6. Return `{ token, user: { id, name, email, tier } }`

### 2.3 Login Endpoint

```
POST /api/auth/login
Body: { email, password }
No auth required.
```

**Logic:**
1. Validate credentials via `Auth::attempt()`
2. Revoke all existing tokens (`$user->tokens()->delete()`)
3. Issue 90-day personal access token
4. Return `{ token, user: { id, name, email, tier } }`

### 2.4 Link Initiation Endpoint

```
POST /api/v1/link/initiate
Header: Authorization: Bearer {token}
Body: { provider: "schwab" }
```

**Logic:**
1. Check `$user->canLinkMoreAccounts()` → 403 if free + already has 1
2. Create `link_sessions:{session_id}` cache entry (user_id + provider, 10 min TTL)
3. Return `{ link_session_id, authorize_url: "{APP_URL}/auth/link/{session_id}" }`

### 2.5 Link Route

```
GET /auth/link/{session_id}
```

**Logic:**
1. Validate session_id exists in cache (don't consume yet — callback will)
2. Store session_id in Laravel session for use in callback
3. Redirect to Schwab OAuth (same authorize URL flow)

### 2.6 Callback Updates

`SchwabCallbackController` delegates to `LinkAccountService` (see 1.7). Three entry scenarios:

```
Schwab OAuth callback:
  1. Exchange code, fetch account hashes
  2. Delegate to LinkAccountService::resolveOrCreateAccount()
     which handles: refresh / claim (FOR UPDATE) / reject / create
  3. Handle redirect:
     → Link session? Redirect to CLI callback with success
     → Waitlist invite? Redirect to CLI callback or web success page
     → Anonymous PKCE? Redirect to Passport authorize URL (existing flow)
```

### 2.7 Passport Configuration

```php
// In AppServiceProvider::boot()
Passport::personalAccessTokensExpireIn(now()->addDays(90));
```

---

## Phase 3: Rate Limiting

**Goal:** Enforce 15 queries/day for free users, block month/year timeframes. Day boundary based on user timezone.

### 3.1 RateLimitCliQuery Middleware

```php
// Middleware: RateLimitCliQuery
//
// Flow:
//   request → free user? → has X-Query-ID? → new query ID today?
//     │          │              │                   │
//     │ no       │ yes          │ no → 400          │ no (retry) → pass
//     │          │              │                   │
//     │          │              │           under limit? → count + pass
//     └─ pass    └──────────────┘              │
//                                              │ no → 429
//
public function handle(Request $request, Closure $next)
{
    $user = $request->user();
    if ($user->tier() !== 'free') {
        return $next($request);
    }

    $queryId = $request->header('X-Query-ID');
    if (!$queryId) {
        return response()->json(['error' => 'missing_query_id'], 400);
    }

    // Use client timezone for day boundary, validated against PHP timezone list
    $tz = $request->header('X-Timezone', 'UTC');
    if (!in_array($tz, timezone_identifiers_list())) {
        $tz = 'UTC';
    }
    $today = now($tz)->toDateString();

    // Check if this query ID was already counted today
    $cacheKey = "cli_query:{$user->id}:{$queryId}";
    $isNewQuery = !Cache::has($cacheKey);

    if ($isNewQuery) {
        $dailyKey = "cli_daily:{$user->id}:{$today}";
        $count = (int) Cache::get($dailyKey, 0);

        if ($count >= User::FREE_DAILY_LIMIT) {
            return response()->json([
                'error' => 'rate_limit_exceeded',
                'message' => 'Free tier limit: ' . User::FREE_DAILY_LIMIT . ' queries/day. Upgrade at mytendies.app/pricing',
                'remaining' => 0,
            ], 429);
        }

        Cache::put($dailyKey, $count + 1, now($tz)->endOfDay());
        Cache::put($cacheKey, true, now($tz)->endOfDay());
    }

    $dailyKey = "cli_daily:{$user->id}:{$today}";
    $remaining = max(0, User::FREE_DAILY_LIMIT - (int) Cache::get($dailyKey, 0));
    $response = $next($request);
    $response->headers->set('X-RateLimit-Remaining', $remaining);

    return $response;
}
```

### 3.2 RestrictTimeframe Middleware

```php
public function handle(Request $request, Closure $next)
{
    $user = $request->user();
    if ($user->tier() !== 'free') {
        return $next($request);
    }

    $start = Carbon::parse($request->query('start'));
    $end = Carbon::parse($request->query('end'));
    $daysDiff = $start->diffInDays($end);

    if ($daysDiff > User::FREE_MAX_DAYS) {
        return response()->json([
            'error' => 'timeframe_restricted',
            'message' => 'Free tier supports day and week queries. Upgrade for month/year.',
            'max_days' => User::FREE_MAX_DAYS,
        ], 403);
    }

    return $next($request);
}
```

### 3.3 Route Updates

```php
Route::middleware(['auth:api'])->prefix('v1')->group(function () {
    // Rate limited + timeframe restricted (replaces EnsureSubscribed)
    Route::middleware(['rate-limit-cli', 'restrict-timeframe'])->group(function () {
        Route::get('/accounts', [AccountController::class, 'index']);
        Route::get('/transactions', [TransactionController::class, 'index']);
    });

    // No rate limit
    Route::get('/subscription', [SubscriptionController::class, 'status']);
    Route::post('/subscription/checkout', [SubscriptionController::class, 'checkout']);
    Route::post('/subscription/portal', [SubscriptionController::class, 'portal']);
    Route::post('/link/initiate', [LinkController::class, 'initiate']);
    Route::post('/account/upgrade', [AccountUpgradeController::class, 'upgrade']);
});

// No auth required
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
```

**Note:** `EnsureSubscribed` middleware is deleted. Free users CAN query — they're just rate limited.

---

## Phase 4: Waitlist System

**Goal:** Controlled onboarding via email signup → admin approve → invite email → Schwab OAuth.

### 4.1 Migration: `create_waitlist_entries_table`

```sql
CREATE TABLE waitlist_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    status VARCHAR(255) NOT NULL DEFAULT 'pending',  -- pending, invited, accepted
    invite_token VARCHAR(64) NULL UNIQUE,
    invited_at TIMESTAMP NULL,
    invite_expires_at TIMESTAMP NULL,
    accepted_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    INDEX (status),
    INDEX (invite_token)
);
```

### 4.2 WaitlistEntry Model

```php
class WaitlistEntry extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'status'];

    protected $casts = [
        'invited_at' => 'datetime',
        'invite_expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->invite_expires_at && $this->invite_expires_at->isPast();
    }

    public function isPending(): bool { return $this->status === 'pending'; }
    public function isInvited(): bool { return $this->status === 'invited'; }
    public function isAccepted(): bool { return $this->status === 'accepted'; }
}
```

### 4.3 Public Waitlist Endpoint

```
POST /api/waitlist/signup
Body: { name, email }
```

**Logic:**
1. Validate name, email (valid format, unique in waitlist_entries)
2. Create entry with `status: pending`
3. Return `{ message: "You're on the list!", position: N }`

### 4.4 WaitlistService

Extract invite-sending logic for testability (Nova action delegates to this):

```php
class WaitlistService
{
    /**
     * Send invites to the given waitlist entries.
     * Skips entries that are not in 'pending' status (prevents double-send).
     *
     * @return array{sent: int, skipped: int}
     */
    public function sendInvites(Collection $entries): array;
}
```

### 4.5 Nova Resource + Bulk Action

**WaitlistEntry Nova Resource:**
- List view: name, email, status (filterable), created_at
- Status badge: pending (grey), invited (yellow), accepted (green)
- Prevent editing invited/accepted entries

**Send Invite Bulk Action:**
- Select entries → "Send Invite"
- Calls `WaitlistService::sendInvites()`
- Shows confirmation: "Sent N invites (skipped M already invited)"

### 4.6 Invite Verification Route

```
GET /auth/waitlist/verify?token={token}
```

**Logic:**
1. Find entry by token
2. Validate: exists, status is `invited`, not expired
3. Store token in session
4. Redirect to Schwab OAuth (same flow, but callback checks for invite token)

### 4.7 Callback Integration

Handled by `LinkAccountService::resolveOrCreateAccount()` when `$inviteToken` is provided:
1. Find waitlist entry by token, validate not expired/used
2. Create User with email and name from waitlist entry
3. Create TradingAccount + hashes
4. Set waitlist entry `status → accepted`, `accepted_at → now`
5. Grant 7-day trial

---

## Phase 5: Profile & Account Management Endpoints (Deferred)

See [TODOS.md](TODOS.md) items #1 and #2. Not needed for CLI MVP. Implement when web app requires it.

Only endpoint needed now:

```
GET /api/v1/accounts → list TradingAccounts (for `tendies account list`)
```

---

## Migration Order & Dependencies

```
Phase 1 ← no dependencies (data model foundation)
Phase 2 ← depends on Phase 1 (needs TradingAccount model)
Phase 3 ← depends on Phase 2 (needs auth endpoints working)
Phase 4 ← independent of Phase 2/3 (can run in parallel with Phase 2)
```

**Recommended order:** Phase 1 → Phase 2 + Phase 4 (parallel) → Phase 3

---

## CLI Changes Required (Summary)

| CLI Change | Backend Dependency |
|------------|-------------------|
| `account create` (terminal prompts) | Phase 2: `POST /api/auth/register` |
| `account upgrade` (terminal prompts, has token) | Phase 2: `POST /api/v1/account/upgrade` |
| `account login` (terminal prompts) | Phase 2: `POST /api/auth/login` |
| `account link --provider schwab` (browser) | Phase 2: `POST /api/v1/link/initiate` + browser |
| `account link` (prompt for provider) | Phase 2: same |
| `account list` | Phase 2: `GET /api/v1/accounts` |
| `account set-default <name>` | Local config only (no backend call) |
| `--account=<name>` flag | Resolve name → hash locally from cached account list |
| `X-Query-ID` header on all requests | Phase 3: rate limit counting |
| `X-Timezone` header on all requests | Phase 3: rate limit day boundary |
| Block `--month`/`--year` on free tier | Phase 3: enforce client-side too |
| XDG config support (`~/.config/tendies/`) | No backend dependency |
| `--direct` mode | Unchanged — account commands error with "requires broker mode" |

---

## Test Plan

### Existing tests to update

| Test File | Changes |
|-----------|---------|
| `SchwabCallbackTest` (14 tests) | Rewrite for TradingAccount + hash pivot + LinkAccountService |
| `SchwabServiceTest` (20 tests) | Swap User → TradingAccount params, add refresh lock tests |
| `TransactionTest` (17 tests) | Resolve via TradingAccount |
| `AccountTest` (6 tests) | Resolve via TradingAccount |
| `EnsureSubscribedMiddlewareTest` (6 tests) | Delete — middleware removed |

### New test files

| Test File | Cases | Codepaths |
|-----------|-------|-----------|
| `AuthRegisterTest` | 4 | R1-R4: valid, duplicate email, bad password, missing fields |
| `AuthLoginTest` | 4 | L1-L4: valid + token revocation, wrong password, not found, missing fields |
| `AccountUpgradeTest` | 4 | U1-U4: valid + token revocation, already registered, email taken, unauthed |
| `LinkInitiateTest` | 4 | LI1-LI4: pro user, free + has account, free + no account, unauthed |
| `LinkRouteTest` | 3 | LR1-LR3: valid session, expired, consumed |
| `LinkAccountServiceTest` | 11 | C1-C9 branching + CL1-CL2 race/cascade |
| `RateLimitCliQueryTest` | 5 | RL1-RL5: pro bypass, under limit, retry, at limit, missing header |
| `RestrictTimeframeTest` | 3 | TF1-TF3: pro bypass, within 7d, exceeds 7d |
| `WaitlistSignupTest` | 3 | W1-W3: valid, duplicate email, missing fields |
| `WaitlistVerifyTest` | 4 | WV1-WV4: valid, expired, invalid, already accepted |
| `UserTierTest` | 5 | T1-T3 tier(), CA1-CA2 canLinkMoreAccounts() |
| `MigrationTest` | 3 | M1-M3: user with token, without token, hash pivot creation |

**Total: ~53 new test cases + ~43 updated existing tests**

---

## Cleanup (Post-Migration)

- Remove `schwab_account_hash` column from `users` table (separate migration)
- Remove legacy `@schwab.local` email handling in callback
- Delete `EnsureSubscribed` middleware
- Keep `AutoLoginFromCache` — still needed for anonymous PKCE flow (document scope)
- Update factories: add `TradingAccountFactory`, `TradingAccountHashFactory`
- Update `CreatesSubscribedUser` trait to create TradingAccount
