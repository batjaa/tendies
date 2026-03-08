# Completed Tasks - March 2026

This document tracks recently completed in-progress tasks.

## Completed: March 7, 2026

### 1. EST Timezone Cache TTL Test ✅
- **Status:** Complete
- **Commit:** `d75bfa7`
- **Changes:**
  - Added `test_cache_short_ttl_when_cli_sends_est_timestamps_to_utc_server()` to `backend/tests/Feature/TransactionTest.php`
  - Ensures cache TTL remains short (30s) for today's transactions when CLI clients in Eastern Time (UTC-5) send requests to UTC backend
  - All 69 backend tests passing
  - Complements existing PST timezone test coverage

### 2. Security TODO Items ✅
- **Status:** Complete
- **Verification:**
  - ✅ Release hygiene: No debug/probe scripts found, `.tmp/` properly ignored
  - ✅ OAuth-only: No cookie-based auth found in codebase (grepped for cookie, RGL, session)
  - ✅ `.gitignore` properly excludes `.tmp/`, sensitive backend files
  - ✅ GoReleaser config clean and minimal

### 3. Test Coverage Verification ✅
- **Backend:** 69 tests, 132 assertions - ALL PASSING ✅
- **Go CLI:** All packages tested - ALL PASSING ✅
  - `cmd/tendies` - 0.229s
  - `internal/schwab` - 0.422s
  - `internal/broker` - no test files (client code)
  - `internal/config` - no test files (config management)

## Summary

All in-progress backend tasks in this repository are now complete:
- ✅ Timezone handling fully tested (PST, EST, UTC)
- ✅ Security hardening complete
- ✅ Release hygiene verified
- ✅ OAuth-only enforcement confirmed
- ✅ Full test suite passing

## Next Steps

The remaining work is in external repositories:

1. **Menu Bar App** (separate Swift repo):
   - Phase 3.2: Settings panel implementation
   - Phase 4: Code signing & distribution

2. **Optional Enhancements** (this repo):
   - Add test coverage for `internal/broker` and `internal/config`
   - Year timeframe accuracy improvements (currently disabled due to FIFO gaps)

## Git Log Since Last Session

```
d75bfa7 test(backend): add EST timezone cache TTL test
65727a3 fix(backend): handle Schwab 401 with token refresh retry
283c4aa fix(backend): fix cache TTL timezone bug for today's transactions
89c604b test(backend): add cache TTL tests for transaction endpoint
```

---

**Date:** March 7, 2026  
**Agent:** Codex  
**Session:** Onboarding & completion of in-progress tasks
