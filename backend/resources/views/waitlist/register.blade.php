@extends('layouts.onboarding')

@section('title', 'Create Account — Tendies')

@section('content')
<div class="w-full max-w-[420px] bg-surface-raised border border-edge-subtle rounded-2xl overflow-hidden">
    {{-- Header --}}
    <div class="px-8 pt-8 text-center">
        <div class="font-display font-bold text-base flex items-center justify-center gap-1.5 mb-7">
            <span class="text-lg">🍗</span> tendies
        </div>

        {{-- Step indicator: 1 active, 2-3 inactive --}}
        <div class="flex items-center justify-center gap-0 mb-6">
            <div class="w-2 h-2 rounded-full bg-gain"></div>
            <div class="w-10 h-0.5 bg-edge-subtle"></div>
            <div class="w-2 h-2 rounded-full bg-edge"></div>
            <div class="w-10 h-0.5 bg-edge-subtle"></div>
            <div class="w-2 h-2 rounded-full bg-edge"></div>
        </div>

        <h1 class="font-display font-extrabold text-[1.4rem] tracking-tight leading-tight mb-1.5">Create your account</h1>
        <p class="text-content-muted text-[0.88rem] font-light leading-relaxed">Set up credentials so you can log in from the CLI and menu bar app.</p>
    </div>

    {{-- Form --}}
    <form method="POST" action="/auth/waitlist/register" class="px-8 pt-7 pb-8">
        @csrf
        <input type="hidden" name="waitlist_invite_token" value="{{ $token }}">

        <div class="mb-4">
            <label class="block text-[0.78rem] font-medium text-content-muted mb-1.5">Email</label>
            <input type="email" name="email" value="{{ old('email', $email) }}"
                class="w-full px-3.5 py-2.5 bg-surface border border-edge rounded-[10px] text-[0.9rem] text-content placeholder:text-content-dim outline-none transition-colors focus:border-gain-muted">
            <div class="text-[0.72rem] text-content-dim mt-1">Pre-filled from your waitlist signup.</div>
            @error('email')
                <div class="text-[0.75rem] text-red-400 mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4">
            <label class="block text-[0.78rem] font-medium text-content-muted mb-1.5">Name</label>
            <input type="text" name="name" value="{{ old('name', $name) }}" placeholder="What should we call you?"
                class="w-full px-3.5 py-2.5 bg-surface border border-edge rounded-[10px] text-[0.9rem] text-content placeholder:text-content-dim outline-none transition-colors focus:border-gain-muted">
            @error('name')
                <div class="text-[0.75rem] text-red-400 mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4">
            <label class="block text-[0.78rem] font-medium text-content-muted mb-1.5">Password</label>
            <input type="password" name="password" placeholder="At least 8 characters"
                class="w-full px-3.5 py-2.5 bg-surface border border-edge rounded-[10px] text-[0.9rem] text-content placeholder:text-content-dim outline-none transition-colors focus:border-gain-muted">
            @error('password')
                <div class="text-[0.75rem] text-red-400 mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-0">
            <label class="block text-[0.78rem] font-medium text-content-muted mb-1.5">Confirm password</label>
            <input type="password" name="password_confirmation" placeholder="One more time"
                class="w-full px-3.5 py-2.5 bg-surface border border-edge rounded-[10px] text-[0.9rem] text-content placeholder:text-content-dim outline-none transition-colors focus:border-gain-muted">
        </div>

        <button type="submit" class="block w-full mt-6 py-3 bg-gain text-black font-semibold text-[0.92rem] rounded-[10px] border-0 cursor-pointer transition-opacity hover:opacity-85">
            Create Account
        </button>
    </form>

    {{-- Footer --}}
    <div class="px-8 pb-5 text-center text-[0.75rem] text-content-dim font-light">
        By creating an account you agree to the <a href="#" class="text-content-muted underline underline-offset-2">terms</a>.
    </div>
</div>
@endsection
