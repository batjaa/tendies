@extends('layouts.app')

@section('title', 'Account — Tendies')

@section('content')

{{-- Profile --}}
<div class="bg-surface-raised border border-edge-subtle rounded-2xl px-6 py-5 mb-5">
    <div class="flex items-center justify-between mb-4">
        <h2 class="font-display font-bold text-[1.1rem]">Profile</h2>
        @php
            $badgeColor = match($tier) {
                'pro' => 'bg-gain/10 text-gain border-gain/20',
                'trial' => 'bg-accent/10 text-accent border-accent/20',
                default => 'bg-surface border-edge text-content-dim',
            };
            $badgeLabel = match($tier) {
                'pro' => 'Pro',
                'trial' => 'Trial',
                default => 'Free',
            };
        @endphp
        <span class="font-mono text-[0.72rem] font-semibold uppercase tracking-wider px-2.5 py-1 rounded-md border {{ $badgeColor }}">{{ $badgeLabel }}</span>
    </div>
    <div class="flex flex-col bg-surface border border-edge-subtle rounded-[10px] px-5 py-1">
        <div class="flex justify-between items-center py-3">
            <span class="text-[0.78rem] text-content-dim">Name</span>
            <span class="text-[0.85rem] font-medium">{{ $user->name }}</span>
        </div>
        <div class="flex justify-between items-center py-3 border-t border-edge-subtle">
            <span class="text-[0.78rem] text-content-dim">Email</span>
            <span class="text-[0.85rem] font-medium">{{ $user->email }}</span>
        </div>
        @if($tier === 'trial' && $user->trial_ends_at)
        <div class="flex justify-between items-center py-3 border-t border-edge-subtle">
            <span class="text-[0.78rem] text-content-dim">Trial ends</span>
            <span class="text-[0.85rem] font-medium text-accent">{{ $user->trial_ends_at->format('F j, Y') }} ({{ $user->trial_ends_at->diffForHumans() }})</span>
        </div>
        @endif
    </div>
</div>

{{-- Connected Brokerages --}}
<div class="bg-surface-raised border border-edge-subtle rounded-2xl px-6 py-5 mb-5">
    <h2 class="font-display font-bold text-[1.1rem] mb-4">Connected Brokerages</h2>

    @if($user->tradingAccounts->isNotEmpty())
    <div class="flex flex-col bg-surface border border-edge-subtle rounded-[10px] px-5 py-1">
        @foreach($user->tradingAccounts as $account)
        <div class="flex justify-between items-center py-3 {{ !$loop->first ? 'border-t border-edge-subtle' : '' }}">
            <div class="flex items-center gap-2.5">
                <span class="text-[0.85rem] font-medium capitalize">{{ $account->provider }}</span>
                @if($account->hashes->isNotEmpty())
                    <span class="text-[0.78rem] text-content-dim font-mono">···{{ substr($account->hashes->first()->hash_value, -3) }}</span>
                @endif
            </div>
            @if($account->is_primary)
                <span class="text-[0.68rem] font-semibold uppercase tracking-wider text-gain">Primary</span>
            @endif
        </div>
        @endforeach
    </div>
    @else
    <p class="text-[0.85rem] text-content-dim">No brokerages connected yet.</p>
    @endif

    @if($user->canLinkMoreAccounts())
    <a href="{{ route('account.connect.schwab') }}" class="block w-full mt-4 py-2.5 text-center text-[0.85rem] font-medium border border-edge rounded-[10px] text-content-muted no-underline transition-all hover:text-content hover:border-content-dim">
        Connect Schwab Account
    </a>
    @endif
</div>

{{-- Subscription --}}
<div class="bg-surface-raised border border-edge-subtle rounded-2xl px-6 py-5 mb-5">
    <h2 class="font-display font-bold text-[1.1rem] mb-4">Subscription</h2>

    @if($subscription)
        <div class="flex flex-col bg-surface border border-edge-subtle rounded-[10px] px-5 py-1 mb-4">
            <div class="flex justify-between items-center py-3">
                <span class="text-[0.78rem] text-content-dim">Plan</span>
                <span class="text-[0.85rem] font-medium capitalize">{{ $subscription['plan'] }}</span>
            </div>
            <div class="flex justify-between items-center py-3 border-t border-edge-subtle">
                <span class="text-[0.78rem] text-content-dim">Status</span>
                <span class="text-[0.85rem] font-medium {{ $subscription['status'] === 'active' ? 'text-gain' : 'text-red-400' }}">{{ str_replace('_', ' ', ucfirst($subscription['status'])) }}</span>
            </div>
        </div>
        <form method="POST" action="{{ route('account.billing') }}">
            @csrf
            <button type="submit" class="block w-full py-2.5 text-center text-[0.85rem] font-medium border border-edge rounded-[10px] text-content-muted cursor-pointer bg-transparent transition-all hover:text-content hover:border-content-dim">
                Manage Billing
            </button>
        </form>
    @else
        @if($tier === 'trial')
            <p class="text-[0.85rem] text-content-muted mb-4">Your trial ends {{ $user->trial_ends_at->format('F j, Y') }}. Subscribe to keep using broker mode.</p>
        @else
            <p class="text-[0.85rem] text-content-muted mb-4">Subscribe to access broker mode, the menu bar app, and managed token refresh.</p>
        @endif
        <div class="flex gap-3">
            <form method="POST" action="{{ route('account.checkout') }}" class="flex-1">
                @csrf
                <input type="hidden" name="plan" value="monthly">
                <button type="submit" class="block w-full py-2.5 text-center text-[0.85rem] font-medium border border-edge rounded-[10px] text-content-muted cursor-pointer bg-transparent transition-all hover:text-content hover:border-content-dim">
                    $5/month
                </button>
            </form>
            <form method="POST" action="{{ route('account.checkout') }}" class="flex-1">
                @csrf
                <input type="hidden" name="plan" value="yearly">
                <button type="submit" class="block w-full py-2.5 text-center text-[0.85rem] font-semibold bg-gain text-black rounded-[10px] border-0 cursor-pointer transition-opacity hover:opacity-85">
                    $40/year
                </button>
            </form>
        </div>
    @endif
</div>

{{-- Account Actions --}}
<div class="mt-1">
    <a href="{{ route('account.password') }}" class="text-[0.85rem] text-content-muted no-underline hover:text-content transition-colors">Change Password</a>
</div>

@endsection
