@extends('layouts.onboarding')

@section('title', 'Reset Password — Tendies')

@section('content')
<div class="w-full max-w-[420px] bg-surface-raised border border-edge-subtle rounded-2xl overflow-hidden">
    {{-- Header --}}
    <div class="px-8 pt-8 text-center">
        <div class="font-display font-bold text-base flex items-center justify-center gap-1.5 mb-7">
            <span class="text-lg">🍗</span> tendies
        </div>

        <h1 class="font-display font-extrabold text-[1.4rem] tracking-tight leading-tight mb-1.5">Set a new password</h1>
        <p class="text-content-muted text-[0.88rem] font-light leading-relaxed">Choose a new password for your account.</p>
    </div>

    {{-- Form --}}
    <form method="POST" action="{{ route('password.update') }}" class="px-8 pt-7 pb-8">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ $email }}">

        <div class="mb-0">
            <label class="block text-[0.78rem] font-medium text-content-muted mb-1.5">New password</label>
            <input type="password" name="password" placeholder="At least 8 characters" autofocus
                class="w-full px-3.5 py-2.5 bg-surface border border-edge rounded-[10px] text-[0.9rem] text-content placeholder:text-content-dim outline-none transition-colors focus:border-gain-muted">
            @error('password')
                <div class="text-[0.75rem] text-red-400 mt-1">{{ $message }}</div>
            @enderror
        </div>

        @error('email')
            <div class="text-[0.75rem] text-red-400 mt-3">{{ $message }}</div>
        @enderror

        <button type="submit" class="block w-full mt-6 py-3 bg-gain text-black font-semibold text-[0.92rem] rounded-[10px] border-0 cursor-pointer transition-opacity hover:opacity-85">
            Reset Password
        </button>
    </form>

    {{-- Footer --}}
    <div class="px-8 pb-5 text-center text-[0.75rem] text-content-dim font-light">
        <a href="{{ route('login') }}" class="text-content-muted underline underline-offset-2">Back to login</a>
    </div>
</div>
@endsection
