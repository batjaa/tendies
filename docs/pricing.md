# Tendies Pricing Strategy

## Product Tiers

| Tier | What | Price |
|------|------|-------|
| **CLI (direct mode)** | Free, open-source. User brings own Schwab developer credentials. | Free |
| **Tendies Pro** | Menu bar app + hosted broker service. No Schwab credentials needed. | $5/mo or $40/yr |

7-day free trial for Pro (aligns with Schwab token expiry cycle).

## What Users Are Paying For

1. **No Schwab developer app required** — getting approved for Schwab API access is friction most traders won't deal with. The broker service removes this entirely.
2. **Always-on P&L in the menu bar** — saves opening Schwab's UI to check the number that matters.
3. **Managed token refresh** — Schwab tokens expire every 7 days. The broker handles this transparently.

## Why $5/mo

- Low enough to be an impulse decision for someone trading thousands daily.
- High enough to cover hosting costs with a modest user base.
- Below $5/mo: not worth billing/support overhead.
- Above $10/mo: competing with full trading dashboards (not what this is).

## Cost Structure

| Item | Estimated Cost |
|------|---------------|
| Digital Ocean (Forge) | $10-50/mo depending on scale |
| Domain / SSL | ~$15/yr |
| Apple Developer Program | $99/yr |
| Schwab API | Free (rate-limited) |

**Break-even:** ~10 annual subscribers covers a basic droplet + Apple dev account.

## Constraints

### Natural Churn Risk

Traders having a bad month may cancel. Annual pricing ($40/yr = 2 months free) helps buffer this and improves revenue predictability.

## Scaling — Schwab API Rate Limits

The rate limit is **120 requests/min per Schwab app** (per `client_id`, not per user token). This is the binding constraint — not server costs.

### Revenue at Scale

| Users | Monthly ($5/mo) | Annual ($40/yr) | Blended (~70/30) |
|-------|----------------|-----------------|-------------------|
| 1,000 | $5,000/mo | $3,333/mo | ~$4,500/mo |
| 3,000 | $15,000/mo | $10,000/mo | ~$13,500/mo |
| 5,000 | $25,000/mo | $16,667/mo | ~$22,500/mo |

Server costs stay under $100/mo even at thousands of users. Margins are 95%+.

### Current API Usage (Unoptimized)

Per refresh cycle, the CLI triggers:
- 1 accounts call (uncached)
- 4 transaction calls (1 per timeframe, 5-min cache for today)

This averages **~1 Schwab API call/min per active user**. At 120 req/min per app:

| Concurrent active users | Schwab calls/min | Apps needed |
|------------------------|-------------------|-------------|
| 100 | ~100 | 1 |
| 500 | ~500 | 5 |
| 2,000 | ~2,000 | 17 |

Registering 17 Schwab apps is not realistic. Must optimize.

### Optimizations (Required for ~1K+ Users)

| Optimization | Impact | Effort |
|---|---|---|
| **Single year-range fetch** — fetch one transaction call for the full year, split into timeframes client-side | 4 calls → 1 (biggest win) | Medium — change CLI + backend |
| **Cache accounts** — account numbers don't change, cache 24h | ~20% fewer calls | Low — add `Cache::remember` to `AccountController` |
| **Increase transaction cache TTL** — 5 min → 15 min | ~3x fewer cache misses | Trivial — change TTL constant |
| **Skip market-closed hours** — no new trades on evenings/weekends, serve from cache | Eliminates ~70% of wall-clock time | Low — check market hours before fetching |

### Optimized API Usage

| State | Calls/min per active user |
|---|---|
| Current (unoptimized) | ~1.0 |
| + Cache accounts (24h) | ~0.8 |
| + Single year-range fetch | ~0.2 |
| + 15-min cache TTL | ~0.07 |
| + Market hours only | 0.07 during market, 0 otherwise |

At **~0.07 calls/min**, one Schwab app supports **~1,700 concurrent users**. A few thousand total users (not all active simultaneously) fits comfortably in a single app.

### Priority Order

1. Single year-range fetch (biggest win, do first)
2. Cache accounts endpoint (easy, do alongside)
3. Market-hours gating (straightforward)
4. Bump transaction cache TTL (trivial knob to turn)

## Funnel

```
CLI (free, direct mode)
  └─► "Want it without Schwab credentials?"
        └─► Broker mode (free trial, 7 days)
              └─► Menu bar app (Pro subscription)
```

The CLI stays free forever. It's the top-of-funnel and proves the P&L engine works. The menu bar app + broker service is the paid product.

## Real-Time Updates vs. Polling

### What Schwab Offers

Schwab has a **WebSocket streaming API** with an `ACCT_ACTIVITY` channel that pushes order fills and account events in real-time. This is a client-initiated WebSocket connection — not a webhook (Schwab will not POST to your server).

### Options

| Approach | Complexity | Server Cost | Latency |
|----------|-----------|-------------|---------|
| Poll on interval (v1) | Low | Low | 1-5 min |
| Menu bar app connects to Schwab streamer directly (direct mode only) | Medium | None | ~1s |
| Backend holds Schwab WebSocket per user, pushes to app via SSE | High | High (persistent connections) | ~1s |

### Decision

**v1: Interval polling.** Simple, cheap, good enough. Day traders check P&L periodically, not tick-by-tick. The 1-min default refresh covers the common case.

**v2 (if demand): Direct-mode streaming.** The menu bar app holds a WebSocket to Schwab's streamer itself. No server cost, real-time updates. Only works in direct mode (user has own Schwab credentials).

**Not planned: Broker-mode streaming.** Holding a persistent WebSocket per user on the server is expensive and complex — bad ROI for a $5/mo product. If broker-mode users want real-time, they can switch to direct mode.
