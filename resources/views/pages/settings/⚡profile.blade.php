<?php

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::lamma'), Title('Profile settings')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';
    public string $preferred_locale = 'ar';

    /** Shows "Profile updated." until the form is edited again. */
    public bool $saved = false;

    public function mount(): void
    {
        $user = Auth::user();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->preferred_locale = $user->preferred_locale;
        $this->saved = (bool) session('profile-saved');
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    /**
     * Update the profile for the currently authenticated user. The language is saved on the account and applied to this session
     * at once: the page reloads so <html lang dir> flips with it.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            ...$this->profileRules($user->id),
            'preferred_locale' => ['required', Rule::in(array_keys(config('locales.supported')))],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $localeChanged = $validated['preferred_locale'] !== app()->getLocale();
        session()->put('locale', $validated['preferred_locale']);

        if ($localeChanged) {
            session()->flash('profile-saved', true);
            $this->redirect(route('profile.edit'));

            return;
        }

        $this->saved = true;
    }

    /** Only reachable when email verification is switched on (Fortify's Features::emailVerification() and MustVerifyEmail on User). */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('me.games', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail || Auth::user()->hasVerifiedEmail();
    }
}; ?>

<x-pages::settings.layout current="profile" :subtitle="__('Manage your profile and account settings')">
    <x-lamma.card :title="__('Profile')" :description="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="flex flex-col gap-5">
            <x-lamma.field name="name" wire:model="name" :value="$name" dir="auto" :label="__('Name')" required autocomplete="name" />

            <x-lamma.field name="email" type="email" wire:model="email" :value="$email" :label="__('Email address')" required autocomplete="email">
                @if ($this->hasUnverifiedEmail)
                    <p class="text-sm text-ink-muted">
                        {{ __('Your email address is unverified.') }}
                        <button type="button" wire:click="resendVerificationNotification" class="font-semibold text-coral-700 underline underline-offset-4">{{ __('Click here to re-send the verification email.') }}</button>
                    </p>
                    @if (session('status') === 'verification-link-sent')
                        <p role="status" class="text-sm font-semibold text-teal-800">{{ __('A new verification link has been sent to your email address.') }}</p>
                    @endif
                @endif
            </x-lamma.field>

            <div class="flex flex-col gap-2">
                <span class="text-[15px] font-semibold">{{ __('Language') }}</span>
                <x-lamma.segmented
                    wire:model="preferred_locale"
                    :label="__('Language')"
                    :options="collect(config('locales.supported'))->map(fn ($locale, $code) => ['label' => $locale['name'], 'lang' => $code])->all()"
                />
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <x-lamma.button type="submit" data-test="update-profile-button">{{ __('Save') }}</x-lamma.button>

                <p role="status" class="flex items-center gap-1.5 text-[15px] font-semibold text-teal-800" data-test="saved">
                    @if ($saved)
                        <x-lamma.icon name="check" :size="20" :stroke="2.4" />
                        {{ __('Profile updated.') }}
                    @endif
                </p>
            </div>
        </form>
    </x-lamma.card>

    @if ($this->showDeleteUser)
        <livewire:pages::settings.delete-user-form />
    @endif
</x-pages::settings.layout>
