@extends('layouts.onboarding')

@section('title', 'Connect Brokerage — Tendies')

@section('content')
<div class="w-full max-w-[420px] bg-surface-raised border border-edge-subtle rounded-2xl overflow-hidden">
    {{-- Header --}}
    <div class="px-8 pt-8 text-center">
        <div class="font-display font-bold text-base flex items-center justify-center gap-1.5 mb-7">
            <span class="text-lg">🍗</span> tendies
        </div>

        {{-- Step indicator: 1 done, 2 active, 3 inactive --}}
        <div class="flex items-center justify-center gap-0 mb-6">
            <div class="w-2 h-2 rounded-full bg-gain-muted"></div>
            <div class="w-10 h-0.5 bg-gain-muted"></div>
            <div class="w-2 h-2 rounded-full bg-gain"></div>
            <div class="w-10 h-0.5 bg-edge-subtle"></div>
            <div class="w-2 h-2 rounded-full bg-edge"></div>
        </div>

        <h1 class="font-display font-extrabold text-[1.4rem] tracking-tight leading-tight mb-1.5">Connect your brokerage</h1>
        <p class="text-content-muted text-[0.88rem] font-light leading-relaxed">Link your trading account so we can pull your transactions and calculate P&L.</p>
    </div>

    {{-- Provider list --}}
    <div class="px-8 pt-7 pb-8">
        {{-- Schwab (active) --}}
        <a href="/onboarding/connect/schwab" class="flex items-center gap-4 p-5 bg-surface border border-edge rounded-xl mb-3 no-underline text-content transition-colors hover:border-gain-muted group">
            <div class="w-11 h-11 rounded-[10px] bg-[#00a0df] text-white flex items-center justify-center font-display font-bold text-[0.85rem] tracking-tight shrink-0">CS</div>
            <div>
                <h3 class="font-display text-[0.95rem] font-bold mb-0.5">Charles Schwab</h3>
                <p class="text-[0.78rem] text-content-muted font-light">thinkorswim, Schwab.com</p>
            </div>
            <div class="ml-auto text-content-dim text-[0.85rem] group-hover:text-gain">&#8250;</div>
        </a>

        {{-- TD Ameritrade (disabled) --}}
        <div class="flex items-center gap-4 p-5 bg-surface border border-edge rounded-xl mb-3 opacity-40">
            <div class="w-11 h-11 rounded-[10px] bg-edge text-content-dim flex items-center justify-center font-display font-bold text-[0.85rem] shrink-0">TD</div>
            <div>
                <h3 class="font-display text-[0.95rem] font-bold mb-0.5">TD Ameritrade</h3>
                <p class="text-[0.78rem] text-content-dim font-light">Coming soon</p>
            </div>
        </div>

        {{-- Interactive Brokers (disabled) --}}
        <div class="flex items-center gap-4 p-5 bg-surface border border-edge rounded-xl opacity-40">
            <div class="w-11 h-11 rounded-[10px] bg-edge text-content-dim flex items-center justify-center font-display font-bold text-[0.85rem] shrink-0">IB</div>
            <div>
                <h3 class="font-display text-[0.95rem] font-bold mb-0.5">Interactive Brokers</h3>
                <p class="text-[0.78rem] text-content-dim font-light">Coming soon</p>
            </div>
        </div>
    </div>

    {{-- Footer --}}
    <div class="px-8 pb-5 text-center text-[0.75rem] text-content-dim font-light">
        You'll be redirected to your broker's login page to authorize read-only access.
    </div>
</div>
@endsection
