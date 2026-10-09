<x-layouts::auth :title="__('Two-factor authentication')">
    <div class="flex flex-col gap-6">
        <div
            class="relative h-auto w-full"
            x-cloak
            x-data="{
                showRecoveryInput: @js($errors->has('recovery_code')),
                code: '',
                recovery_code: '',
                focusOtp() {
                    this.$nextTick(() => this.$refs.otp?.querySelector('input')?.focus());
                },
                init() {
                    if (! this.showRecoveryInput) {
                        this.focusOtp();
                    }
                },
                toggleInput() {
                    this.showRecoveryInput = !this.showRecoveryInput;

                    this.code = '';
                    this.recovery_code = '';

                    $nextTick(() => {
                        this.showRecoveryInput
                            ? this.$refs.recovery_code?.focus()
                            : this.focusOtp();
                    });
                },
            }"
        >
            <div x-show="!showRecoveryInput">
                <x-auth-header
                    :title="__('Authentication code')"
                    :description="__('Enter the authentication code provided by your authenticator application.')"
                />
            </div>

            <div x-show="showRecoveryInput">
                <x-auth-header
                    :title="__('Recovery code')"
                    :description="__('Please confirm access to your account by entering one of your emergency recovery codes.')"
                />
            </div>

            <form method="POST" action="{{ route('two-factor.login.store') }}">
                @csrf

                <div class="space-y-5 text-center">
                    <div x-show="!showRecoveryInput" class="my-5 text-start" x-ref="otp">
                        <x-lamma.otp name="code" :label="__('OTP Code')" x-model="code" x-bind:required="! showRecoveryInput" />
                    </div>

                    <div x-show="showRecoveryInput" class="my-5 text-start">
                        <x-lamma.field
                            name="recovery_code" :label="__('Recovery code')" dir="ltr"
                            x-ref="recovery_code" x-bind:required="showRecoveryInput" autocomplete="one-time-code" x-model="recovery_code"
                        />
                    </div>

                    <x-lamma.button type="submit" size="lg" class="w-full">{{ __('Continue') }}</x-lamma.button>
                </div>

                <p class="mt-5 text-center text-sm text-ink-muted">
                    {{ __('or you can') }}
                    <button type="button" x-on:click="toggleInput()" class="font-semibold text-coral-700 underline underline-offset-4">
                        <span x-show="!showRecoveryInput">{{ __('login using a recovery code') }}</span>
                        <span x-show="showRecoveryInput" x-cloak>{{ __('login using an authentication code') }}</span>
                    </button>
                </p>
            </form>
        </div>
    </div>
</x-layouts::auth>
