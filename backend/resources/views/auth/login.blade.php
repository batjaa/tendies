@extends('layouts.onboarding')

@section('title', 'Log In — Tendies')

@section('content')
<div class="w-full max-w-[420px] bg-surface-raised border border-edge-subtle rounded-2xl overflow-hidden">
    {{-- Header --}}
    <div class="px-8 pt-8 text-center">
        <div class="font-display font-bold text-base flex items-center justify-center gap-1.5 mb-7">
            <span class="text-lg">🍗</span> tendies
        </div>

        <h1 class="font-display font-extrabold text-[1.4rem] tracking-tight leading-tight mb-1.5">Welcome back</h1>
        <p class="text-content-muted text-[0.88rem] font-light leading-relaxed">Log in to manage your account and connected brokerages.</p>
    </div>

    {{-- Form --}}
    <form method="POST" action="{{ route('login') }}" class="px-8 pt-7 pb-8">
        @csrf

        <div class="mb-4">
            <label class="block text-[0.78rem] font-medium text-content-muted mb-1.5">Email</label>
            <input type="email" name="email" value="{{ old('email') }}" autofocus
                class="w-full px-3.5 py-2.5 bg-surface border border-edge rounded-[10px] text-[0.9rem] text-content placeholder:text-content-dim outline-none transition-colors focus:border-gain-muted">
            @error('email')
                <div class="text-[0.75rem] text-red-400 mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-1">
            <label class="block text-[0.78rem] font-medium text-content-muted mb-1.5">Password</label>
            <input type="password" name="password"
                class="w-full px-3.5 py-2.5 bg-surface border border-edge rounded-[10px] text-[0.9rem] text-content placeholder:text-content-dim outline-none transition-colors focus:border-gain-muted">
            @error('password')
                <div class="text-[0.75rem] text-red-400 mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-0 text-right">
            <a href="{{ route('password.request') }}" class="text-[0.75rem] text-content-muted no-underline hover:text-content transition-colors">Forgot password?</a>
        </div>

        <button type="submit" class="block w-full mt-5 py-3 bg-gain text-black font-semibold text-[0.92rem] rounded-[10px] border-0 cursor-pointer transition-opacity hover:opacity-85">
            Log In
        </button>
    </form>

    {{-- Footer --}}
    <div class="px-8 pb-5 text-center text-[0.75rem] text-content-dim font-light">
        Don't have an account? <a href="/#waitlist" class="text-content-muted underline underline-offset-2">Join the waitlist</a>.
    </div>
</div>
@endsection
