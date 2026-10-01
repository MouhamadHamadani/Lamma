<x-layouts::auth :title="__('Reset password')">
    <x-auth-header :title="__('Reset password')" :description="__('Please enter your new password below')" />

    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-5">
        @csrf
        <input type="hidden" name="token" value="{{ request()->route('token') }}">

        <x-lamma.field name="email" type="email" :label="__('Email')" :value="request('email')" required autocomplete="email" />

        <x-lamma.field
            name="password" type="password" :label="__('Password')" viewable required autofocus autocomplete="new-password" :placeholder="__('Password')"
            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
        />

        <x-lamma.field
            name="password_confirmation" type="password" :label="__('Confirm password')" viewable required autocomplete="new-password" :placeholder="__('Confirm password')"
            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
        />

        <x-lamma.button type="submit" size="lg" class="w-full" data-test="reset-password-button">{{ __('Reset password') }}</x-lamma.button>
    </form>
</x-layouts::auth>
