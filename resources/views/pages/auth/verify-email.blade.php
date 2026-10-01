<x-layouts::auth :title="__('Email verification')">
    <p class="text-[17px] text-ink-muted">{{ __('Please verify your email address by clicking on the link we just emailed to you.') }}</p>

    @if (session('status') == 'verification-link-sent')
        <x-auth-session-status :status="__('A new verification link has been sent to the email address you provided during registration.')" />
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <x-lamma.button type="submit" size="lg" class="w-full">{{ __('Resend verification email') }}</x-lamma.button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="self-center">
        @csrf
        <x-lamma.button type="submit" variant="ghost" data-test="logout-button">{{ __('Log out') }}</x-lamma.button>
    </form>
</x-layouts::auth>
