<x-layouts::auth :title="__('Log in')">
    <x-auth-header :title="__('Welcome back')" :description="__('Log in to host a game or pick up where you left off.')" />

    <x-lamma.segmented
        :label="__('Log in or sign up')"
        selected="login"
        :options="['login' => ['label' => __('Log in'), 'href' => route('login')], 'register' => ['label' => __('Sign up'), 'href' => route('register')]]"
    />

    <x-auth-session-status :status="session('status')" />

    <x-passkey-verify />

    <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5">
        @csrf

        <x-lamma.field name="email" type="email" :label="__('Email address')" required autofocus autocomplete="email" placeholder="email@example.com" />

        <x-lamma.field name="password" type="password" :label="__('Password')" viewable required autocomplete="current-password" :placeholder="__('Password')">
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="self-end text-sm font-semibold text-coral-700 hover:underline">{{ __('Forgot your password?') }}</a>
            @endif
        </x-lamma.field>

        <label class="flex items-center gap-3 text-[15px] font-semibold">
            <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-5 accent-navy">
            {{ __('Remember me') }}
        </label>

        <x-lamma.button type="submit" size="lg" class="w-full" data-test="login-button">{{ __('Log in') }}</x-lamma.button>
    </form>

    <div class="flex items-center gap-3 text-[13px] font-semibold text-ink-subtle" aria-hidden="true">
        <span class="h-px grow bg-line"></span>{{ __('or') }}<span class="h-px grow bg-line"></span>
    </div>

    <a href="{{ route('join') }}" class="flex min-h-16 items-center justify-between gap-3 rounded-btn border-2 border-dashed border-line-strong px-5 py-2 font-semibold hover:bg-tint-navy">
        <span>{{ __("Joining a friend's game?") }} <b>{{ __('Enter a room code') }}</b></span>
        <x-lamma.icon name="arrow-right" :size="20" class="rtl:-scale-x-100" />
    </a>
</x-layouts::auth>
