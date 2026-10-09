<?php

use App\Concerns\PasswordValidationRules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::lamma'), Title('Security settings')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /** Shows "Password updated." until the form is edited again. */
    public bool $passwordSaved = false;

    public bool $canManageTwoFactor;

    public bool $twoFactorEnabled;

    public bool $requiresConfirmation;

    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
    }

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'password') || $name === 'current_password') {
            $this->passwordSaved = false;
        }
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->passwordSaved = true;
    }

    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Remember which passkey the confirmation dialog is about (the page opens the dialog once this returns).
     */
    public function confirmDelete(int $passkeyId): void
    {
        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
    }

    /**
     * Delete the passkey.
     */
    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        if (! $this->deletingPasskeyId) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(auth()->user(), $passkey);

        $this->closeDeleteModal();
        $this->dispatch('close-dialog', name: 'remove-passkey');
        $this->loadPasskeys();
    }

    /**
     * The confirmation dialog was dismissed.
     */
    public function closeDeleteModal(): void
    {
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }

    /**
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $disableTwoFactorAuthentication(auth()->user());

        $this->twoFactorEnabled = false;
    }
}; ?>

<x-pages::settings.layout current="security" :subtitle="__('Keep your account safe')">
    {{-- PASSWORD --}}
    <x-lamma.card :title="__('Update password')" :description="__('Ensure your account is using a long, random password to stay secure')">
        <form wire:submit="updatePassword" class="flex flex-col gap-5">
            <x-lamma.field name="current_password" type="password" wire:model="current_password" :label="__('Current password')" viewable required autocomplete="current-password" />
            <x-lamma.field
                name="password" type="password" wire:model="password" :label="__('New password')" viewable required autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
            />
            <x-lamma.field
                name="password_confirmation" type="password" wire:model="password_confirmation" :label="__('Confirm password')" viewable required autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
            />

            <div class="flex flex-wrap items-center gap-4">
                <x-lamma.button type="submit" data-test="update-password-button">{{ __('Save') }}</x-lamma.button>

                <p role="status" class="flex items-center gap-1.5 text-[15px] font-semibold text-teal-800" data-test="saved">
                    @if ($passwordSaved)
                        <x-lamma.icon name="check" :size="20" :stroke="2.4" />
                        {{ __('Password updated.') }}
                    @endif
                </p>
            </div>
        </form>
    </x-lamma.card>

    {{-- TWO-FACTOR AUTHENTICATION --}}
    @if ($canManageTwoFactor)
        <x-lamma.card :title="__('Two-factor authentication')" :description="__('Manage your two-factor authentication settings')" data-test="two-factor">
            @if ($twoFactorEnabled)
                <p class="text-ink-muted">
                    {{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}
                </p>

                <div>
                    <x-lamma.button variant="danger" wire:click="disable" data-test="disable-two-factor">{{ __('Disable 2FA') }}</x-lamma.button>
                </div>

                <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
            @else
                <p class="text-ink-muted">
                    {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}
                </p>

                <div>
                    <x-lamma.button icon="qr" wire:click="$dispatch('start-two-factor-setup')" x-data x-on:click="$dispatch('open-dialog', { name: 'two-factor-setup' })" data-test="enable-two-factor">
                        {{ __('Enable 2FA') }}
                    </x-lamma.button>
                </div>

                <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
            @endif
        </x-lamma.card>
    @endif

    {{-- PASSKEYS --}}
    @if ($canManagePasskeys)
        <x-lamma.card :title="__('Passkeys')" :description="__('Manage your passkeys for passwordless sign-in')" data-test="passkeys">
            <ul class="flex flex-col overflow-hidden rounded-tile border-2 border-line" data-test="passkey-list">
                @forelse ($passkeys as $passkey)
                    <li class="flex items-center justify-between gap-3 p-4 {{ ! $loop->last ? 'border-b-2 border-line' : '' }}" wire:key="passkey-{{ $passkey['id'] }}">
                        <div class="flex min-w-0 items-center gap-4">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-input bg-tint-navy" aria-hidden="true">
                                <x-lamma.icon name="key" :size="22" />
                            </span>
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                    <bdi class="truncate font-bold">{{ $passkey['name'] }}</bdi>
                                    @if ($passkey['authenticator'])
                                        <x-lamma.chip size="sm" tone="line">{{ $passkey['authenticator'] }}</x-lamma.chip>
                                    @endif
                                </div>
                                <p class="text-sm text-ink-subtle">
                                    {{ __('Added :time', ['time' => $passkey['created_at_diff']]) }}
                                    @if ($passkey['last_used_at_diff'])
                                        <span aria-hidden="true">·</span>
                                        {{ __('Last used :time', ['time' => $passkey['last_used_at_diff']]) }}
                                    @endif
                                </p>
                            </div>
                        </div>

                        <button
                            type="button" x-data
                            x-on:click="$wire.confirmDelete({{ $passkey['id'] }}).then(() => $dispatch('open-dialog', { name: 'remove-passkey' }))"
                            aria-label="{{ __('Remove passkey') }}: {{ $passkey['name'] }}"
                            class="flex size-11 shrink-0 items-center justify-center rounded-input text-coral-700 hover:bg-tint-coral"
                            data-test="remove-passkey"
                        >
                            <x-lamma.icon name="trash" :size="20" />
                        </button>
                    </li>
                @empty
                    <li class="flex flex-col items-center gap-1 p-8 text-center">
                        <span class="mb-2 flex size-14 items-center justify-center rounded-2xl bg-tint-navy" aria-hidden="true">
                            <x-lamma.icon name="key" :size="28" />
                        </span>
                        <p class="font-bold">{{ __('No passkeys yet') }}</p>
                        <p class="text-ink-muted">{{ __('Add a passkey to sign in without a password') }}</p>
                    </li>
                @endforelse
            </ul>

            <x-passkey-registration />
        </x-lamma.card>

        @php
            $removeText = __('Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.', ['name' => $deletingPasskeyName ?: '…']);
        @endphp
        <x-lamma.dialog
            name="remove-passkey"
            :title="__('Remove passkey')"
            :description="$removeText"
            x-on:close="$wire.closeDeleteModal()"
        >
            <div class="flex flex-wrap justify-end gap-3">
                <x-lamma.button variant="outline" x-on:click="$el.closest('dialog').close()">{{ __('Cancel') }}</x-lamma.button>
                <x-lamma.button variant="danger" wire:click="deletePasskey" icon="trash">{{ __('Remove passkey') }}</x-lamma.button>
            </div>
        </x-lamma.dialog>
    @endif
</x-pages::settings.layout>
