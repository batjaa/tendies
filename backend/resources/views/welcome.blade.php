<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tendies — Realized Schwab P&L in Your Terminal</title>
    <meta name="description" content="Track realized trading gains and losses from Schwab with FIFO lot matching. Free CLI tool and macOS menu bar app.">
    <meta property="og:title" content="Tendies — Realized Schwab P&L">
    <meta property="og:description" content="Your trading P&L, always visible. CLI + macOS menu bar app for Schwab.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://tendies.batjaa.site">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🍗</text></svg>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700;12..96,800&family=Outfit:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css'])
</head>
<body class="bg-surface text-content leading-relaxed antialiased overflow-x-hidden">

<div class="dot-grid"></div>

{{-- ────────── Nav ────────── --}}
<nav id="nav" class="sticky top-0 z-50 py-4 backdrop-blur-xl bg-surface/80 border-b border-transparent transition-[border-color] duration-300">
    <div class="max-w-[1120px] mx-auto px-6">
        <div class="flex items-center justify-between">
            <a href="/" class="font-display font-bold text-xl text-content no-underline flex items-center gap-2">
                <span class="text-[1.4rem]">🍗</span> tendies
            </a>
            <ul class="hidden md:flex items-center gap-8 list-none">
                <li><a href="#features" class="nav-link text-content-muted no-underline text-sm hover:text-content">Features</a></li>
                <li><a href="#cli" class="nav-link text-content-muted no-underline text-sm hover:text-content">CLI</a></li>
                <li><a href="#menubar" class="nav-link text-content-muted no-underline text-sm hover:text-content">Menu Bar</a></li>
                <li><a href="#pricing" class="nav-link text-content-muted no-underline text-sm hover:text-content">Pricing</a></li>
                <li><a href="#direct" class="nav-link text-content-muted no-underline text-sm hover:text-content">Direct Mode</a></li>
            </ul>
            <a href="#pricing" class="bg-gain text-black px-[18px] py-2 rounded-lg font-semibold text-[0.85rem] no-underline transition-opacity hover:opacity-85">Get Started</a>
        </div>
    </div>
</nav>

{{-- ────────── Hero ────────── --}}
<section class="hero-glow relative z-[1] pt-20 pb-15 md:pt-[120px] md:pb-20 text-center">
    <div class="max-w-[1120px] mx-auto px-6">
        <div class="font-mono text-gain font-semibold leading-tight tracking-tight mb-6 hero-enter hero-enter-1" style="font-size:clamp(3.5rem,8vw,6rem);text-shadow:0 0 60px rgba(34,197,94,0.15)">
            ▲ +$1,234
        </div>
        <h1 class="font-display font-extrabold leading-tight tracking-tight mb-4 hero-enter hero-enter-2" style="font-size:clamp(2rem,4vw,3rem)">
            Your P&L, always visible.
        </h1>
        <p class="text-content-muted text-lg max-w-[520px] mx-auto mb-10 font-light leading-relaxed hero-enter hero-enter-3">
            Track realized gains and losses from Schwab with FIFO lot matching.
            In your terminal or your menu bar.
        </p>
        <div class="inline-flex flex-col md:flex-row items-center gap-2 md:gap-3 bg-surface-raised border border-edge rounded-xl px-5 py-3 mb-8 font-mono text-sm hero-enter hero-enter-4">
            <code class="text-content-muted"><span class="text-gain">$</span> <span class="text-content">brew install batjaa/tap/tendies</span></code>
            <button class="copy-btn bg-surface-card border border-edge text-content-muted px-2.5 py-1.5 rounded-md cursor-pointer text-[0.8rem] font-sans flex items-center gap-1" onclick="copyInstall(this)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                Copy
            </button>
        </div>
        <div class="flex gap-4 justify-center flex-wrap hero-enter hero-enter-5">
            <a href="#pricing" class="bg-gain text-black px-7 py-3 rounded-[10px] font-semibold text-[0.95rem] no-underline transition-opacity hover:opacity-85">Get Started</a>
            <a href="https://github.com/batjaa/tendies" class="border border-edge text-content-muted px-7 py-3 rounded-[10px] font-medium text-[0.95rem] no-underline transition-all hover:text-content hover:border-content-dim">View on GitHub</a>
        </div>
    </div>
</section>

{{-- ────────── Features ────────── --}}
<section class="relative z-[1] pt-15 pb-20" id="features">
    <div class="max-w-[1120px] mx-auto px-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="p-7 bg-surface-raised border border-edge-subtle rounded-[14px] transition-[border-color] duration-300 hover:border-edge reveal">
                <span class="text-2xl mb-3.5 block">📊</span>
                <h3 class="font-display text-[1.1rem] font-bold mb-2">FIFO Lot Matching</h3>
                <p class="text-content-muted text-sm leading-relaxed font-light">Computes realized P&L from Schwab transactions using first-in-first-out matching, the same method used by most brokers.</p>
            </div>
            <div class="p-7 bg-surface-raised border border-edge-subtle rounded-[14px] transition-[border-color] duration-300 hover:border-edge reveal reveal-delay-1">
                <span class="text-2xl mb-3.5 block">📅</span>
                <h3 class="font-display text-[1.1rem] font-bold mb-2">Multi-Timeframe</h3>
                <p class="text-content-muted text-sm leading-relaxed font-light">Day, week, month, and year-to-date views. See how your trading performs across every horizon at a glance.</p>
            </div>
            <div class="p-7 bg-surface-raised border border-edge-subtle rounded-[14px] transition-[border-color] duration-300 hover:border-edge reveal reveal-delay-2">
                <span class="text-2xl mb-3.5 block">🔍</span>
                <h3 class="font-display text-[1.1rem] font-bold mb-2">Symbol Filtering</h3>
                <p class="text-content-muted text-sm leading-relaxed font-light">Drill into specific tickers. Filter by symbol or underlying to see exactly where your gains and losses are coming from.</p>
            </div>
        </div>
    </div>
</section>

{{-- ────────── CLI ────────── --}}
<section class="relative z-[1] py-20" id="cli">
    <div class="max-w-[1120px] mx-auto px-6">
        <div class="grid grid-cols-1 md:grid-cols-[1fr_1.2fr] gap-10 md:gap-15 items-center">
            <div class="reveal">
                <div class="font-mono text-[0.8rem] text-gain uppercase tracking-wider mb-3">CLI</div>
                <h2 class="font-display font-extrabold tracking-tight mb-3" style="font-size:clamp(1.75rem,3vw,2.25rem)">Built for the terminal.</h2>
                <p class="text-content-muted text-[1.05rem] max-w-[520px] font-light leading-relaxed">
                    One command gives you realized P&L across every timeframe.
                    Filter by symbol, pick an account, or enable debug mode for full lot-matching detail.
                </p>
            </div>
            <div class="terminal reveal reveal-delay-1">
                <div class="terminal-chrome">
                    <div class="terminal-dot red"></div>
                    <div class="terminal-dot yellow"></div>
                    <div class="terminal-dot green"></div>
                    <div class="terminal-title">tendies --day</div>
                </div>
                <div class="terminal-body"><span class="prompt">$</span> <span class="cmd">tendies --day</span>

<span class="dim">Tendies Realized P&L (2026-03-04 15:30:00 EST)</span>
<span class="dim">Account: ...789</span>

<span class="label">Period        Gains        Losses           Net   Trades</span>
<span class="dim">──────────────────────────────────────────────────────────</span>
Day       <span class="gain">$1,500.00</span>      <span class="loss">-$265.44</span>    <span class="gain">+$1,234.56</span>       12

<span class="label">Trades:</span>
  <span class="label">Time              Symbol                          Qty          P&L     Hold</span>
  <span class="dim">───────────────────────────────────────────────────────────────────────────────</span>
  Mar 04 09:31      NVDA                            100     <span class="gain">+$456.78</span>      23m
  Mar 04 09:45      TSLA                             50     <span class="gain">+$234.12</span>      15m
  Mar 04 10:12      AMD                             200      <span class="loss">-$89.45</span>       8m
  Mar 04 11:02      META                            150     <span class="gain">+$633.11</span>      45m</div>
            </div>
        </div>
    </div>
</section>

{{-- ────────── Menu Bar ────────── --}}
<section class="relative z-[1] py-20" id="menubar">
    <div class="max-w-[1120px] mx-auto px-6">
        <div class="grid grid-cols-1 md:grid-cols-[1.2fr_1fr] gap-10 md:gap-15 items-center">
            <div class="flex flex-col items-center gap-2 order-2 md:order-none reveal">
                <div class="mac-statusbar">
                    <span class="pnl-label">▲ +$1,234</span>
                    <span class="sys-icons">
                        <span>Wi-Fi</span>
                        <span>100%</span>
                        <span>3:30 PM</span>
                    </span>
                </div>
                <div class="mac-popover">
                    <div class="popover-header">
                        <span class="app-name">Tendies</span>
                        <span class="actions">↻&nbsp;&nbsp;⚙</span>
                    </div>
                    <div class="popover-divider"></div>
                    <div class="account-bar">
                        <span class="account-label">Acct</span>
                        <span class="account-chip selected">···789</span>
                        <span class="account-chip selected">···234</span>
                        <span class="account-chip">···567</span>
                    </div>
                    <div class="popover-rows">
                        {{-- Day — expanded with ticker drill-down --}}
                        <div class="popover-row expanded">
                            <span class="tf-chevron">▾</span>
                            <span class="tf-label">Day</span>
                            <span class="tf-pnl" style="color:#3fb950">+$1,234.56</span>
                            <span class="tf-trades">12 trades</span>
                        </div>
                        <div class="ticker-list">
                            <div class="ticker-row expanded-ticker">
                                <span class="ticker-chevron">▾</span>
                                <span class="ticker-symbol">HD</span>
                                <span class="ticker-pnl" style="color:#3fb950">+$892.30</span>
                                <span class="ticker-count">4 exe</span>
                            </div>
                            <div class="exec-list">
                                <div class="exec-row">
                                    <span class="exec-detail">9:47 SELL 50sh @ $385.10</span>
                                    <span class="exec-pnl" style="color:#3fb950">+$132.50</span>
                                </div>
                                <div class="exec-matched">
                                    <span>└ opened 9:32 &nbsp;50sh @ $382.45</span>
                                </div>
                                <div class="exec-row">
                                    <span class="exec-detail">10:15 SELL 50sh @ $397.68</span>
                                    <span class="exec-pnl" style="color:#3fb950">+$759.80</span>
                                </div>
                                <div class="exec-matched">
                                    <span>└ opened 9:32 &nbsp;50sh @ $382.45</span>
                                </div>
                            </div>
                            <div class="ticker-row">
                                <span class="ticker-chevron">▸</span>
                                <span class="ticker-symbol">META</span>
                                <span class="ticker-pnl" style="color:#3fb950">+$567.26</span>
                                <span class="ticker-count">3 exe</span>
                            </div>
                            <div class="ticker-row">
                                <span class="ticker-chevron">▸</span>
                                <span class="ticker-symbol">MU</span>
                                <span class="ticker-pnl" style="color:#f85149">-$225.00</span>
                                <span class="ticker-count">2 exe</span>
                            </div>
                        </div>
                        {{-- Week — collapsed --}}
                        <div class="popover-row">
                            <span class="tf-chevron">▸</span>
                            <span class="tf-label">Week</span>
                            <span class="tf-pnl" style="color:#3fb950">+$3,456.78</span>
                            <span class="tf-trades">45 trades</span>
                        </div>
                        {{-- Month — collapsed --}}
                        <div class="popover-row">
                            <span class="tf-chevron">▸</span>
                            <span class="tf-label">Month</span>
                            <span class="tf-pnl" style="color:#f85149">-$2,100.00</span>
                            <span class="tf-trades">98 trades</span>
                        </div>
                    </div>
                    <div class="popover-divider"></div>
                    <div class="popover-footer">
                        <span>Updated 2m ago</span>
                        <span style="color:#e5e5e7">Quit</span>
                    </div>
                </div>
            </div>
            <div class="order-1 md:order-none reveal reveal-delay-1">
                <div class="font-mono text-[0.8rem] text-gain uppercase tracking-wider mb-3">Menu Bar App</div>
                <h2 class="font-display font-extrabold tracking-tight mb-3" style="font-size:clamp(1.75rem,3vw,2.25rem)">Or just glance up.</h2>
                <p class="text-content-muted text-[1.05rem] max-w-[520px] font-light leading-relaxed">
                    A native macOS menu bar app that shows your realized P&L at a glance.
                    Like battery percentage, but for your gains. Drill down into tickers and individual executions with FIFO-matched lots.
                </p>
                <p class="text-content-muted max-w-[520px] font-light leading-relaxed mt-4 text-sm">
                    Switch accounts, auto-refresh on your schedule. No browser tabs, no Schwab UI.
                    Included with <strong class="text-content">Tendies Pro</strong>.
                </p>
            </div>
        </div>
    </div>
</section>

{{-- ────────── Security & Privacy ────────── --}}
<section class="relative z-[1] py-20" id="direct">
    <div class="max-w-[1120px] mx-auto px-6">
        <div class="text-center mb-12 reveal">
            <div class="font-mono text-[0.8rem] text-gain uppercase tracking-wider mb-3">Security & Privacy</div>
            <h2 class="font-display font-extrabold tracking-tight mb-3" style="font-size:clamp(1.75rem,3vw,2.25rem)">Honest about what touches our server.</h2>
            <p class="text-content-muted text-[1.05rem] max-w-[520px] mx-auto font-light leading-relaxed">
                Your brokerage data is sensitive. Here's exactly what happens in each mode — no vague promises.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-[820px] mx-auto mb-12">
            <div class="bg-surface-raised border border-edge-subtle rounded-[14px] p-8 transition-[border-color] duration-300 hover:border-edge reveal">
                <span class="font-mono text-[0.72rem] font-semibold uppercase tracking-wider text-gain bg-gain/10 border border-gain/15 px-2.5 py-1 rounded-md inline-block mb-4">Broker Mode</span>
                <h3 class="font-display text-[1.15rem] font-bold mb-2.5">Convenient, with trade-offs</h3>
                <p class="text-content-muted text-[0.88rem] font-light leading-relaxed mb-5">Your requests pass through our server, which proxies to the Schwab API on your behalf. This means you don't need your own Schwab developer credentials.</p>
                <ul class="flex flex-col gap-2.5 list-none">
                    <li class="text-[0.85rem] text-content-muted flex items-start gap-2.5 font-light leading-normal"><span class="shrink-0 mt-px">🔐</span> Schwab tokens encrypted at rest (AES-256-CBC)</li>
                    <li class="text-[0.85rem] text-content-muted flex items-start gap-2.5 font-light leading-normal"><span class="shrink-0 mt-px">⏱</span> Transaction data cached temporarily (5 min for today, 7 days for past dates) to reduce API calls</li>
                    <li class="text-[0.85rem] text-content-muted flex items-start gap-2.5 font-light leading-normal"><span class="shrink-0 mt-px">👤</span> No real names or emails stored — accounts identified by token hash</li>
                    <li class="text-[0.85rem] text-content-muted flex items-start gap-2.5 font-light leading-normal"><span class="shrink-0 mt-px">🚫</span> Trading data never sold, shared, or used for analytics</li>
                    <li class="text-[0.85rem] text-content-muted flex items-start gap-2.5 font-light leading-normal"><span class="shrink-0 mt-px">🗑</span> Cache entries expire automatically — no permanent storage of trades</li>
                </ul>
            </div>
            <div class="bg-surface-raised border border-edge-subtle rounded-[14px] p-8 transition-[border-color] duration-300 hover:border-edge reveal reveal-delay-1">
                <span class="font-mono text-[0.72rem] font-semibold uppercase tracking-wider text-accent bg-accent/10 border border-accent/15 px-2.5 py-1 rounded-md inline-block mb-4">Direct Mode</span>
                <h3 class="font-display text-[1.15rem] font-bold mb-2.5">Zero trust, nothing leaves your machine</h3>
                <p class="text-content-muted text-[0.88rem] font-light leading-relaxed mb-5">The CLI talks to the Schwab API directly using your own developer credentials. Our server is never involved.</p>
                <ul class="flex flex-col gap-2.5 list-none">
                    <li class="text-[0.85rem] text-content-muted flex items-start gap-2.5 font-light leading-normal"><span class="shrink-0 mt-px">🏠</span> All API calls go from your machine to Schwab — no middleman</li>
                    <li class="text-[0.85rem] text-content-muted flex items-start gap-2.5 font-light leading-normal"><span class="shrink-0 mt-px">🔑</span> OAuth token stored in your macOS keychain</li>
                    <li class="text-[0.85rem] text-content-muted flex items-start gap-2.5 font-light leading-normal"><span class="shrink-0 mt-px">📖</span> CLI is open source — inspect every line</li>
                    <li class="text-[0.85rem] text-content-muted flex items-start gap-2.5 font-light leading-normal"><span class="shrink-0 mt-px">🌐</span> No server, no account, no tracking</li>
                    <li class="text-[0.85rem] text-content-muted flex items-start gap-2.5 font-light leading-normal"><span class="shrink-0 mt-px">⚡</span> Requires registering your own app at developer.schwab.com</li>
                </ul>
            </div>
        </div>

        <div class="text-center mb-8 reveal">
            <p class="text-content-muted text-[0.95rem] max-w-[520px] mx-auto mb-6">
                <strong class="text-content">Want to go fully local?</strong> Set up direct mode in 4 steps:
            </p>
        </div>

        <div class="flex flex-col gap-4 max-w-[620px] mx-auto reveal">
            <div class="flex gap-4 items-start bg-surface-raised border border-edge-subtle rounded-xl p-5 transition-[border-color] duration-300 hover:border-edge">
                <div class="font-mono text-[0.8rem] font-semibold text-accent bg-accent/10 border border-accent/15 w-8 h-8 rounded-lg flex items-center justify-center shrink-0">1</div>
                <div>
                    <h4 class="font-display font-bold text-[0.95rem] mb-1">Register a Schwab app</h4>
                    <p class="text-content-muted text-[0.85rem] font-light leading-relaxed">Create a developer app at <a href="https://developer.schwab.com" class="text-accent no-underline">developer.schwab.com</a> and note your client ID and secret.</p>
                </div>
            </div>
            <div class="flex gap-4 items-start bg-surface-raised border border-edge-subtle rounded-xl p-5 transition-[border-color] duration-300 hover:border-edge">
                <div class="font-mono text-[0.8rem] font-semibold text-accent bg-accent/10 border border-accent/15 w-8 h-8 rounded-lg flex items-center justify-center shrink-0">2</div>
                <div>
                    <h4 class="font-display font-bold text-[0.95rem] mb-1">Configure credentials</h4>
                    <p class="text-content-muted text-[0.85rem] font-light leading-relaxed">Run <code class="font-mono text-[0.8rem] bg-white/[0.04] px-1.5 py-0.5 rounded border border-edge-subtle text-content">tendies --config</code> and set your <code class="font-mono text-[0.8rem] bg-white/[0.04] px-1.5 py-0.5 rounded border border-edge-subtle text-content">client_id</code>, <code class="font-mono text-[0.8rem] bg-white/[0.04] px-1.5 py-0.5 rounded border border-edge-subtle text-content">client_secret</code>, and <code class="font-mono text-[0.8rem] bg-white/[0.04] px-1.5 py-0.5 rounded border border-edge-subtle text-content">redirect_url</code>.</p>
                </div>
            </div>
            <div class="flex gap-4 items-start bg-surface-raised border border-edge-subtle rounded-xl p-5 transition-[border-color] duration-300 hover:border-edge">
                <div class="font-mono text-[0.8rem] font-semibold text-accent bg-accent/10 border border-accent/15 w-8 h-8 rounded-lg flex items-center justify-center shrink-0">3</div>
                <div>
                    <h4 class="font-display font-bold text-[0.95rem] mb-1">Authenticate directly</h4>
                    <p class="text-content-muted text-[0.85rem] font-light leading-relaxed">Run <code class="font-mono text-[0.8rem] bg-white/[0.04] px-1.5 py-0.5 rounded border border-edge-subtle text-content">tendies login --direct</code> to authorize with Schwab. Token goes to your macOS keychain.</p>
                </div>
            </div>
            <div class="flex gap-4 items-start bg-surface-raised border border-edge-subtle rounded-xl p-5 transition-[border-color] duration-300 hover:border-edge">
                <div class="font-mono text-[0.8rem] font-semibold text-accent bg-accent/10 border border-accent/15 w-8 h-8 rounded-lg flex items-center justify-center shrink-0">4</div>
                <div>
                    <h4 class="font-display font-bold text-[0.95rem] mb-1">Check your P&L</h4>
                    <p class="text-content-muted text-[0.85rem] font-light leading-relaxed">Run <code class="font-mono text-[0.8rem] bg-white/[0.04] px-1.5 py-0.5 rounded border border-edge-subtle text-content">tendies --direct --day</code>. All data stays local.</p>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ────────── Pricing ────────── --}}
<section class="relative z-[1] py-20" id="pricing">
    <div class="max-w-[1120px] mx-auto px-6 text-center">
        <div class="reveal">
            <div class="font-mono text-[0.8rem] text-gain uppercase tracking-wider mb-3">Pricing</div>
            <h2 class="font-display font-extrabold tracking-tight mb-3" style="font-size:clamp(1.75rem,3vw,2.25rem)">Start free. Upgrade when you want the menu bar.</h2>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-[400px] md:max-w-[720px] mx-auto mt-12">
            <div class="bg-surface-raised border border-edge-subtle rounded-2xl px-8 py-9 text-left transition-[border-color] duration-300 hover:border-edge reveal">
                <div class="font-display text-[1.2rem] font-bold mb-1">CLI</div>
                <div class="text-content-muted text-[0.85rem] font-light mb-5">For developers and power users</div>
                <div class="font-display text-[2.5rem] font-extrabold tracking-tighter mb-1">Free</div>
                <div class="text-content-dim text-[0.82rem] mb-6">Forever <span class="opacity-60">(or until my AWS bill becomes sentient)</span></div>
                <ul class="flex flex-col gap-2.5 list-none">
                    <li class="text-[0.88rem] text-content-muted flex items-center gap-2.5 font-light"><span class="text-gain text-sm shrink-0">✓</span> Terminal P&L for all timeframes</li>
                    <li class="text-[0.88rem] text-content-muted flex items-center gap-2.5 font-light"><span class="text-gain text-sm shrink-0">✓</span> Symbol and account filtering</li>
                    <li class="text-[0.88rem] text-content-muted flex items-center gap-2.5 font-light"><span class="text-gain text-sm shrink-0">✓</span> FIFO lot matching engine</li>
                    <li class="text-[0.88rem] text-content-muted flex items-center gap-2.5 font-light"><span class="text-gain text-sm shrink-0">✓</span> Direct mode (bring your own credentials)</li>
                    <li class="text-[0.88rem] text-content-muted flex items-center gap-2.5 font-light"><span class="text-gain text-sm shrink-0">✓</span> Open source</li>
                </ul>
                <a href="#cli" class="block text-center py-3 rounded-[10px] font-semibold text-sm no-underline mt-7 border border-edge text-content transition-all hover:border-content-dim">Install Free</a>
            </div>
            <div class="bg-surface-raised border border-gain-muted rounded-2xl px-8 py-9 text-left relative transition-[border-color] duration-300 hover:border-gain reveal reveal-delay-1">
                <div class="absolute -top-2.5 right-5 bg-gain text-black font-mono text-[0.7rem] font-semibold px-2.5 py-0.5 rounded-md tracking-wide">PRO</div>
                <div class="font-display text-[1.2rem] font-bold mb-1">Tendies Pro</div>
                <div class="text-content-muted text-[0.85rem] font-light mb-5">P&L in your menu bar, zero setup</div>
                <div class="font-display text-[2.5rem] font-extrabold tracking-tighter mb-1">$5<span class="text-base font-normal text-content-muted">/mo</span></div>
                <div class="text-content-dim text-[0.82rem] mb-6">or $40/year (save 2 months) &nbsp;·&nbsp; 7-day free trial</div>
                <ul class="flex flex-col gap-2.5 list-none">
                    <li class="text-[0.88rem] text-content-muted flex items-center gap-2.5 font-light"><span class="text-gain text-sm shrink-0">✓</span> Everything in CLI</li>
                    <li class="text-[0.88rem] text-content-muted flex items-center gap-2.5 font-light"><span class="text-gain text-sm shrink-0">✓</span> macOS menu bar app</li>
                    <li class="text-[0.88rem] text-content-muted flex items-center gap-2.5 font-light"><span class="text-gain text-sm shrink-0">✓</span> Auto-refresh on your schedule</li>
                    <li class="text-[0.88rem] text-content-muted flex items-center gap-2.5 font-light"><span class="text-gain text-sm shrink-0">✓</span> No Schwab developer app needed</li>
                    <li class="text-[0.88rem] text-content-muted flex items-center gap-2.5 font-light"><span class="text-gain text-sm shrink-0">✓</span> Managed token refresh</li>
                </ul>
                <a href="#" class="block text-center py-3 rounded-[10px] font-semibold text-sm no-underline mt-7 bg-gain text-black transition-opacity hover:opacity-85">Start Free Trial</a>
            </div>
        </div>
    </div>
</section>

{{-- ────────── Footer ────────── --}}
<footer class="relative z-[1] py-12 border-t border-edge-subtle mt-10">
    <div class="max-w-[1120px] mx-auto px-6">
        <div class="flex flex-col md:flex-row items-center justify-between gap-4">
            <span class="text-content-dim text-[0.82rem]">© 2026 tendies</span>
            <ul class="flex gap-6 list-none">
                <li><a href="https://github.com/batjaa/tendies" class="text-content-dim text-[0.82rem] no-underline transition-colors hover:text-content-muted">GitHub</a></li>
                <li><a href="https://github.com/batjaa/tendies#local-development" class="text-content-dim text-[0.82rem] no-underline transition-colors hover:text-content-muted">Docs</a></li>
            </ul>
        </div>
    </div>
</footer>

<script>
    // Sticky nav border
    const nav = document.getElementById('nav');
    window.addEventListener('scroll', () => {
        nav.classList.toggle('scrolled', window.scrollY > 10);
    });

    // Copy install command
    function copyInstall(btn) {
        navigator.clipboard.writeText('brew install batjaa/tap/tendies');
        btn.innerHTML = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Copied
        `;
        btn.classList.add('copied');
        setTimeout(() => {
            btn.innerHTML = `
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                Copy
            `;
            btn.classList.remove('copied');
        }, 2000);
    }

    // Scroll reveal
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
            }
        });
    }, { threshold: 0.15 });

    document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

    // Active nav link tracking
    const sections = document.querySelectorAll('section[id]');
    const navAnchors = document.querySelectorAll('.nav-link[href^="#"]');

    const sectionObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const id = entry.target.getAttribute('id');
                navAnchors.forEach(a => {
                    a.classList.toggle('active', a.getAttribute('href') === '#' + id);
                });
            }
        });
    }, { rootMargin: '-30% 0px -60% 0px' });

    sections.forEach(s => sectionObserver.observe(s));
</script>

</body>
</html>
