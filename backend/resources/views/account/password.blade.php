@extends('layouts.app')

@section('title', 'Change Password — Tendies')

@section('content')

<div class="bg-surface-raised border border-edge-subtle rounded-2xl px-6 py-5">
    <h2 class="font-display font-bold text-[1.1rem] mb-5">Change Password</h2>

    <form method="POST" action="{{ route('account.password') }}">
        @csrf

        <div class="mb-4">
            <label class="block text-[0.78rem] font-medium text-content-muted mb-1.5">Current password</label>
            <input type="password" name="current_password"
                class="w-full px-3.5 py-2.5 bg-surface border border-edge rounded-[10px] text-[0.9rem] text-content placeholder:text-content-dim outline-none transition-colors focus:border-gain-muted">
            @error('current_password')
                <div class="text-[0.75rem] text-red-400 mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4">
            <label class="block text-[0.78rem] font-medium text-content-muted mb-1.5">New password</label>
            <input type="password" name="password" placeholder="At least 8 characters"
                class="w-full px-3.5 py-2.5 bg-surface border border-edge rounded-[10px] text-[0.9rem] text-content placeholder:text-content-dim outline-none transition-colors focus:border-gain-muted">
            @error('password')
                <div class="text-[0.75rem] text-red-400 mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-0">
            <label class="block text-[0.78rem] font-medium text-content-muted mb-1.5">Confirm new password</label>
            <input type="password" name="password_confirmation"
                class="w-full px-3.5 py-2.5 bg-surface border border-edge rounded-[10px] text-[0.9rem] text-content placeholder:text-content-dim outline-none transition-colors focus:border-gain-muted">
        </div>

        <button type="submit" class="block w-full mt-6 py-3 bg-gain text-black font-semibold text-[0.92rem] rounded-[10px] border-0 cursor-pointer transition-opacity hover:opacity-85">
            Update Password
        </button>
    </form>
</div>

<div class="mt-4">
    <a href="{{ route('account.show') }}" class="text-[0.85rem] text-content-muted no-underline hover:text-content transition-colors">&larr; Back to account</a>
</div>

@endsection
