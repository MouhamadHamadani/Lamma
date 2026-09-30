<x-layouts::auth :title="__('Welcome')">
    <div class="flex flex-col items-center gap-6 text-center">
        <div class="flex flex-col gap-2">
            <flux:heading size="xl" level="1">{{ config('app.name') }}</flux:heading>
            <flux:subheading>{{ __('A multiplayer quiz party game: one big screen, everyone answers from their phone.') }}</flux:subheading>
        </div>

        <div class="flex items-center gap-3">
            @auth
                <flux:button variant="primary" :href="route('dashboard')" wire:navigate>{{ __('Dashboard') }}</flux:button>
            @else
                <flux:button variant="primary" :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:button>
                @if (Route::has('register'))
                    <flux:button :href="route('register')" wire:navigate>{{ __('Register') }}</flux:button>
                @endif
            @endauth
        </div>
    </div>
</x-layouts::auth>
