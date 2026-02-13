# Release Guide

This project uses GoReleaser + GitHub Actions to publish binaries and update the Homebrew tap.

## One-Time Setup
1. Ensure the tap exists:
   - `https://github.com/batjaa/homebrew-tools`
2. In `batjaa/tendies` GitHub repo settings, add secret:
   - Name: `HOMEBREW_TAP_TOKEN`
   - Value: GitHub PAT with `repo` scope that can push to `batjaa/homebrew-tools`
3. Ensure `.goreleaser.yaml` and `.github/workflows/release.yml` are on `main`.

## Release Steps
1. Make sure `main` is clean and pushed.
2. Create and push a semver tag:

```bash
git tag v0.1.0
git push origin v0.1.0
```

3. Watch workflow run:
   - GitHub Actions -> `release`
4. Verify artifacts:
   - GitHub release created in `batjaa/tendies`
   - Formula updated in `batjaa/homebrew-tools/Formula/tendies.rb`

## Homebrew Install

```bash
brew tap batjaa/tools
brew install tendies
```

## Troubleshooting
- If formula update fails with auth errors:
  - Recreate `HOMEBREW_TAP_TOKEN` with correct scope and access.
- If tag release fails:
  - Ensure tag matches `v*` pattern.
- If GoReleaser fails locally, dry-run:

```bash
go run github.com/goreleaser/goreleaser/v2@latest release --snapshot --clean
```
