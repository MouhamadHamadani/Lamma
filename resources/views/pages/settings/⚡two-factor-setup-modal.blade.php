<?php

use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public bool $requiresConfirmation;

    #[Locked]
    public string $qrCodeSvg = '';

    #[Locked]
    public string $manualSetupKey = '';

    public bool $showVerificationStep = false;

    public bool $setupComplete = false;

    #[Validate('required|string|size:6', onUpdate: false)]
    public string $code = '';

    /**
     * Mount the component.
     */
    public function mount(bool $requiresConfirmation): void
    {
        $this->requiresConfirmation = $requiresConfirmation;
    }

    #[On('start-two-factor-setup')]
    public function startTwoFactorSetup(): void
    {
        $enableTwoFactorAuthentication = app(EnableTwoFactorAuthentication::class);
        $enableTwoFactorAuthentication(auth()->user());

        $this->loadSetupData();
    }

    /**
     * Load the two-factor authentication setup data for the user.
     */
    private function loadSetupData(): void
    {
        $user = auth()->user()?->fresh();

        try {
            if (! $user || ! $user->two_factor_secret) {
                throw new Exception('Two-factor setup secret is not available.');
            }

            $this->qrCodeSvg = $user->twoFactorQrCodeSvg();
            $this->manualSetupKey = decrypt($user->two_factor_secret);
        } catch (Exception) {
            $this->addError('setupData', 'Failed to fetch setup data.');

            $this->reset('qrCodeSvg', 'manualSetupKey');
        }
    }

    /**
     * Show the two-factor verification step if necessary.
     */
    public function showVerificationIfNecessary(): void
    {
        if ($this->requiresConfirmation) {
            $this->showVerificationStep = true;

            $this->resetErrorBag();

            return;
        }

        $this->closeModal();
        $this->dispatch('two-factor-enabled');
    }

    /**
     * Confirm two-factor authentication for the user.
     */
    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirmTwoFactorAuthentication): void
    {
        $this->validate();

        $confirmTwoFactorAuthentication(auth()->user(), $this->code);

        $this->setupComplete = true;

        $this->closeModal();

        $this->dispatch('two-factor-enabled');
    }

    /**
     * Reset two-factor verification state.
     */
    public function resetVerification(): void
    {
        $this->reset('code', 'showVerificationStep');

        $this->resetErrorBag();
    }

    /**
     * Close the two-factor authentication modal.
     */
    public function closeModal(): void
    {
        $this->reset(
            'code',
            'manualSetupKey',
            'qrCodeSvg',
            'showVerificationStep',
            'setupComplete',
        );

        $this->resetErrorBag();
    }

    /**
     * Get the current modal configuration state.
     */
    #[Computed]
    public function modalConfig(): array
    {
        if ($this->setupComplete) {
            return [
                'title' => __('Two-factor authentication enabled'),
                'description' => __('Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.'),
                'buttonText' => __('Close'),
            ];
        }

        if ($this->showVerificationStep) {
            return [
                'title' => __('Verify authentication code'),
                'description' => __('Enter the 6-digit code from your authenticator app.'),
                'buttonText' => __('Continue'),
            ];
        }

        return [
            'title' => __('Enable two-factor authentication'),
            'description' => __('To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app.'),
            'buttonText' => __('Continue'),
        ];
    }
}; ?>

<div>
    <x-lamma.dialog
        name="two-factor-setup"
        :title="$this->modalConfig['title']"
        :description="$this->modalConfig['description']"
        x-on:close="$wire.closeModal()"
    >
        @if ($showVerificationStep)
            <div class="flex flex-col gap-5">
                <x-lamma.otp name="code" wire:model="code" :label="__('OTP Code')" x-init="$nextTick(() => $el.focus())" />

                <div class="flex gap-3">
                    <x-lamma.button variant="outline" class="grow" wire:click="resetVerification">{{ __('Back') }}</x-lamma.button>
                    <x-lamma.button class="grow disabled:cursor-not-allowed disabled:opacity-50" wire:click="confirmTwoFactor" x-bind:disabled="$wire.code.length < 6">{{ __('Confirm') }}</x-lamma.button>
                </div>
            </div>
        @else
            @error('setupData')
                <x-lamma.error>{{ $message }}</x-lamma.error>
            @enderror

            <div class="mx-auto flex size-56 items-center justify-center rounded-tile border-2 border-line bg-white p-3 sm:size-64" dir="ltr">
                @empty($qrCodeSvg)
                    <x-lamma.dots />
                @else
                    <div class="size-full [&>svg]:size-full" role="img" aria-label="{{ __('QR code for your authenticator app') }}">{!! $qrCodeSvg !!}</div>
                @endempty
            </div>

            <x-lamma.button class="w-full" :disabled="$errors->has('setupData')" wire:click="showVerificationIfNecessary">
                {{ $this->modalConfig['buttonText'] }}
            </x-lamma.button>

            <div class="flex flex-col gap-3">
                <div class="flex items-center gap-3 text-[13px] font-semibold text-ink-subtle" aria-hidden="true">
                    <span class="h-px grow bg-line"></span>{{ __('or, enter the code manually') }}<span class="h-px grow bg-line"></span>
                </div>

                @if (filled($manualSetupKey))
                    <div class="flex items-stretch overflow-hidden rounded-input border-2 border-line bg-white" dir="ltr" x-data="{
                        copied: false,
                        async copy() {
                            try {
                                await navigator.clipboard.writeText(this.$refs.key.value);
                                this.copied = true;
                                setTimeout(() => this.copied = false, 1500);
                            } catch (e) {
                                this.$refs.key.select();
                            }
                        },
                    }">
                        <input
                            type="text" readonly x-ref="key" value="{{ $manualSetupKey }}" aria-label="{{ __('Setup key') }}" data-test="setup-key"
                            class="min-w-0 grow bg-transparent px-4 py-3 font-mono text-[15px]"
                        >
                        <button
                            type="button" class="flex w-12 items-center justify-center border-s-2 border-line hover:bg-tint-navy"
                            x-on:click="copy()"
                            aria-label="{{ __('Copy setup key') }}"
                        >
                            <x-lamma.icon name="copy" :size="20" x-show="! copied" />
                            <x-lamma.icon name="check" :size="20" :stroke="2.6" x-show="copied" x-cloak />
                        </button>
                    </div>
                @else
                    <div class="flex h-12 items-center justify-center rounded-input border-2 border-line bg-tint-navy"><x-lamma.dots /></div>
                @endif
            </div>
        @endif
    </x-lamma.dialog>
</div>
