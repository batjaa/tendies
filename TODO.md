# Security TODO

This file tracks remaining security hardening items before/after publish.

## Policy Decisions
- Auth mode: OAuth only. Do not rely on cookie-based auth.
- Debug mode: keep enabled; users operate at their own risk when using `--debug`.

## Remaining Items
1. OAuth state hardening in login flow
- Require callback URL input containing both `code` and `state`.
- Reject bare `code` input to enforce CSRF/state validation.

2. Networking hardening for Schwab client calls
- Use `http.NewRequestWithContext` for all API requests.
- Add explicit client/request timeouts and retry/backoff strategy for transient failures.

3. Release hygiene and repo safety
- Ensure temporary debug/probe scripts are excluded from release artifacts.
- Keep `.tmp/` and any local diagnostic outputs out of source control.

4. OAuth-only cleanup
- Remove or archive internal cookie-based RGL auth path from production CLI surface.
- If retained for local experiments, gate clearly behind non-production/dev-only boundaries.
