# 🍗 Tendies

**Your realized gains, served fresh.**

A tool for day traders to monitor realized gains/losses across multiple timeframes. Built in two phases: CLI tool first, then a macOS menu bar app.

## Problem Statement
Day traders need quick visibility into their realized P&L for the day, week, month, and year without manually calculating from trade history.

## Data Source
- **Platform**: Charles Schwab / thinkorswim
- **Integration**: Schwab API (requires Schwab developer account and OAuth authentication)

## Features

### Account Selection
- Support for multiple trading accounts
- Checkbox selection for each account
- "All Accounts" option to aggregate across accounts

### Instrument Filtering
- Configurable instrument types (stocks, options, futures, etc.)
- User can select which instruments to include in P&L calculations

### Timeframes
- **Day**: Realized P&L since midnight (local timezone)
- **Week**: Realized P&L for current calendar week
- **Month**: Realized P&L for current calendar month
- **Year**: Realized P&L year-to-date

### Data Refresh
- Configurable refresh interval
- Default: 1 minute
- Manual refresh option

---

## Phase 1: CLI Tool

### Commands
```
tendies                    # Show all timeframes
tendies --day              # Show today only
tendies --week             # Show this week only
tendies --month            # Show this month only
tendies --year             # Show YTD only
tendies --account <id>     # Filter by account
tendies --config           # Configure settings
```

### Configuration
- Schwab API credentials (stored securely in keychain)
- Default accounts to display
- Instrument filters
- Refresh interval

---

## Phase 2: macOS Menu Bar App

### Menu Bar Display
- Compact display showing selected timeframe(s)
- User configures which to show: Day, Week, and/or Annual
- 🍗 chicken tender icon, color-coded:
  - 🟢 Golden/Green: Positive P&L (tendies secured)
  - 🔴 Red: Negative P&L (tendies lost)
- Click to expand dropdown with full details

### Dropdown Menu
- All timeframes with P&L amounts
- Account breakdown (if multiple selected)
- Last updated timestamp
- Manual refresh button
- Settings/Preferences

### Settings
- Account selection (checkboxes)
- Instrument filters
- Refresh interval
- Menu bar display preferences
- Start at login option

---

## Technical Requirements

### Schwab API Integration
1. Register for Schwab Developer Account
2. Create OAuth application
3. Implement OAuth 2.0 flow for authentication
4. Use Accounts & Trading API for transaction history

### Security
- Store OAuth tokens securely in macOS Keychain
- Never store credentials in plain text
- Support token refresh

### Timezone
- Use local system timezone for day boundaries
- Week starts on Monday

---

## Development Phases

### Phase 1: CLI Tool
1. Schwab API authentication
2. Fetch transaction history
3. Calculate realized P&L by timeframe
4. CLI output formatting
5. Configuration management

### Phase 2: Menu Bar App
1. Port CLI logic to Swift/SwiftUI
2. Menu bar UI implementation
3. Background refresh scheduler
4. System preferences integration
5. App notarization and distribution
