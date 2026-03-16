@extends('layouts.onboarding')

@section('title', "You're All Set — Tendies")

@section('content')
<div class="w-full max-w-[460px] bg-surface-raised border border-edge-subtle rounded-2xl overflow-hidden">
    {{-- Header --}}
    <div class="px-8 pt-8 text-center">
        <div class="font-display font-bold text-base flex items-center justify-center gap-1.5 mb-7">
            <span class="text-lg">🍗</span> tendies
        </div>

        {{-- Step indicator: all done --}}
        <div class="flex items-center justify-center gap-0 mb-6">
            <div class="w-2 h-2 rounded-full bg-gain-muted"></div>
            <div class="w-10 h-0.5 bg-gain-muted"></div>
            <div class="w-2 h-2 rounded-full bg-gain-muted"></div>
            <div class="w-10 h-0.5 bg-gain-muted"></div>
            <div class="w-2 h-2 rounded-full bg-gain-muted"></div>
        </div>

        {{-- Success icon --}}
        <div class="w-14 h-14 rounded-full bg-gain/10 border-2 border-gain/25 flex items-center justify-center mx-auto mb-5 text-gain text-2xl">
            &#10003;
        </div>

        <h1 class="font-display font-extrabold text-[1.4rem] tracking-tight leading-tight mb-1.5">You're all set.</h1>
        <p class="text-content-muted text-[0.88rem] font-light leading-relaxed">Your brokerage is connected and your 7-day trial is active. Time to see some numbers.</p>
    </div>

    {{-- Details --}}
    <div class="px-8 pt-7 pb-8">
        <div class="flex flex-col bg-surface border border-edge-subtle rounded-[10px] px-5 py-4">
            <div class="flex justify-between items-center py-2">
                <span class="text-[0.78rem] text-content-dim">Account</span>
                <span class="text-[0.82rem] font-medium">{{ $user->email }}</span>
            </div>
            @if($tradingAccount)
            <div class="flex justify-between items-center py-2 border-t border-edge-subtle">
                <span class="text-[0.78rem] text-content-dim">Brokerage</span>
                @php $firstHash = $tradingAccount->hashes->first(); @endphp
                <span class="text-[0.82rem] font-medium">Schwab @if($firstHash)···{{ substr($firstHash->hash_value, -3) }}@endif</span>
            </div>
            @endif
            @if($user->trial_ends_at)
            <div class="flex justify-between items-center py-2 border-t border-edge-subtle">
                <span class="text-[0.78rem] text-content-dim">Trial ends</span>
                <span class="text-[0.82rem] font-medium text-gain">{{ $user->trial_ends_at->format('F j, Y') }}</span>
            </div>
            @endif
        </div>

        {{-- Install: Menu bar app --}}
        <div class="mt-6">
            <div class="text-[0.78rem] font-medium text-content-muted mb-2">Install the menu bar app</div>
            <div class="font-mono text-[0.8rem] bg-surface border border-edge-subtle rounded-[10px] px-4 py-3.5 leading-[1.8] text-content-muted">
                <span class="text-gain">$</span> <span class="text-content">brew install --cask batjaa/tap/tendies-app</span>
            </div>
        </div>

        {{-- Install: CLI --}}
        <div class="mt-6">
            <div class="text-[0.78rem] font-medium text-content-muted mb-2">Install the CLI</div>
            <div class="font-mono text-[0.8rem] bg-surface border border-edge-subtle rounded-[10px] px-4 py-3.5 leading-[1.8] text-content-muted">
                <span class="text-gain">$</span> <span class="text-content">brew install batjaa/tap/tendies</span><br>
                <span class="text-gain">$</span> <span class="text-content">tendies login</span><br>
                <span class="text-gain">$</span> <span class="text-content">tendies --day</span>
            </div>
        </div>

        {{-- Dashboard button --}}
        <a href="/account" class="block w-full mt-5 py-3 text-center text-content-muted text-[0.88rem] font-medium border border-edge rounded-[10px] no-underline transition-all hover:text-content hover:border-content-dim">
            Go to Dashboard
        </a>
    </div>

    {{-- Footer --}}
    <div class="px-8 pb-5 text-center text-[0.75rem] text-content-dim font-light">
        After the trial, $5/mo or $40/yr. The CLI in direct mode stays free forever.
    </div>
</div>
@endsection
