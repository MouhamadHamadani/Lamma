<x-layouts::auth :title="__('Register')">
    <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

    <x-lamma.segmented
        :label="__('Log in or sign up')"
        selected="register"
        :options="['login' => ['label' => __('Log in'), 'href' => route('login')], 'register' => ['label' => __('Sign up'), 'href' => route('register')]]"
    />

    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-5">
        @csrf

        <x-lamma.field name="name" :label="__('Name')" dir="auto" required autofocus autocomplete="name" :placeholder="__('Full name')" />

        <x-lamma.field name="email" type="email" :label="__('Email address')" required autocomplete="email" placeholder="email@example.com" />

        <x-lamma.field
            name="password" type="password" :label="__('Password')" viewable required autocomplete="new-password" :placeholder="__('Password')"
            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
        />

        <x-lamma.field
            name="password_confirmation" type="password" :label="__('Confirm password')" viewable required autocomplete="new-password" :placeholder="__('Confirm password')"
            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
        />

        <x-lamma.button type="submit" size="lg" class="w-full" data-test="register-user-button">{{ __('Create account') }}</x-lamma.button>
    </form>

    <p class="text-center text-[15px] text-ink-muted">
        {{ __('Already have an account?') }}
        <a href="{{ route('login') }}" class="font-semibold text-coral-700 hover:underline">{{ __('Log in') }}</a>
    </p>
</x-layouts::auth>
