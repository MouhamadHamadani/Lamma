<x-layouts::auth :title="__('Forgot password')">
    <x-auth-header :title="__('Forgot password')" :description="__('Enter your email to receive a password reset link')" />

    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-5">
        @csrf

        <x-lamma.field name="email" type="email" :label="__('Email address')" required autofocus autocomplete="email" placeholder="email@example.com" />

        <x-lamma.button type="submit" size="lg" class="w-full" data-test="email-password-reset-link-button">{{ __('Email password reset link') }}</x-lamma.button>
    </form>

    <p class="text-center text-[15px] text-ink-muted">
        {{ __('Or, return to') }}
        <a href="{{ route('login') }}" class="font-semibold text-coral-700 hover:underline">{{ __('log in') }}</a>
    </p>
</x-layouts::auth>
