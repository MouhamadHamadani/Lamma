<?php

use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public array $recoveryCodes = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->loadRecoveryCodes();
    }

    /**
     * Generate new recovery codes for the user.
     */
    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        $generateNewRecoveryCodes(auth()->user());

        $this->loadRecoveryCodes();
    }

    /**
     * Load the recovery codes for the user.
     */
    private function loadRecoveryCodes(): void
    {
        $user = auth()->user();

        if ($user->hasEnabledTwoFactorAuthentication() && $user->two_factor_recovery_codes) {
            try {
                $this->recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
            } catch (Exception) {
                $this->addError('recoveryCodes', 'Failed to load recovery codes');

                $this->recoveryCodes = [];
            }
        }
    }
}; ?>

<div x-data="{ showRecoveryCodes: false }" data-test="recovery-codes">
    <x-lamma.card :sticker="false" :title="__('2FA recovery codes')" :description="__('Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.')">
        <div class="flex flex-wrap gap-3">
            <x-lamma.button
                variant="outline" icon="eye"
                x-on:click="showRecoveryCodes = ! showRecoveryCodes"
                x-bind:aria-expanded="showRecoveryCodes" aria-expanded="false" aria-controls="recovery-codes-section"
            >
                <span x-show="! showRecoveryCodes">{{ __('View recovery codes') }}</span>
                <span x-show="showRecoveryCodes" x-cloak>{{ __('Hide recovery codes') }}</span>
            </x-lamma.button>

            @if (filled($recoveryCodes))
                <x-lamma.button variant="outline" icon="refresh" wire:click="regenerateRecoveryCodes" x-show="showRecoveryCodes" x-cloak>
                    {{ __('Regenerate codes') }}
                </x-lamma.button>
            @endif
        </div>

        <div id="recovery-codes-section" x-show="showRecoveryCodes" x-cloak class="flex flex-col gap-3">
            @error('recoveryCodes')
                <x-lamma.error>{{ $message }}</x-lamma.error>
            @enderror

            @if (filled($recoveryCodes))
                <ul class="grid gap-1.5 rounded-input bg-tint-navy p-4 font-mono text-[15px]" dir="ltr" aria-label="{{ __('Recovery codes') }}" data-test="codes">
                    @foreach ($recoveryCodes as $code)
                        <li class="select-text" wire:loading.class="opacity-50 motion-safe:animate-pulse">{{ $code }}</li>
                    @endforeach
                </ul>
                <p class="text-sm text-ink-subtle">
                    {{ __('Each recovery code can be used once to access your account and will be removed after use. If you need more, click Regenerate codes above.') }}
                </p>
            @endif
        </div>
    </x-lamma.card>
</div>
