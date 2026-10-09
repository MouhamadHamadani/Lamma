<?php

use App\Actions\DeleteAccount;
use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user (see DeleteAccount: other players keep their results, hosted open rooms are closed).
     */
    public function deleteUser(Logout $logout, DeleteAccount $deleteAccount): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        $user = Auth::user();

        $logout();
        $deleteAccount($user);

        $this->redirect('/');
    }

    /** The dialog was closed (Cancel or Escape): forget what was typed. */
    public function cancel(): void
    {
        $this->reset('password');
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-lamma.dialog
        name="delete-account"
        :title="__('Are you sure you want to delete your account?')"
        :description="__('Once your account is deleted, your profile and saved games are gone for good. Please enter your password to confirm.')"
        x-on:close="$wire.cancel()"
    >
        <form wire:submit="deleteUser" class="flex flex-col gap-5">
            <x-lamma.field name="password" type="password" wire:model="password" viewable required autocomplete="current-password" :label="__('Password')" />

            <div class="flex flex-wrap justify-end gap-3">
                <x-lamma.button variant="outline" x-on:click="$el.closest('dialog').close()">{{ __('Cancel') }}</x-lamma.button>
                <x-lamma.button variant="danger" type="submit" icon="trash" data-test="confirm-delete-user-button">{{ __('Delete account') }}</x-lamma.button>
            </div>
        </form>
    </x-lamma.dialog>
</div>
