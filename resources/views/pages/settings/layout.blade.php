{{-- Shell for the settings pages: the shared account header, a page title, Profile / Security link tabs, and the page's cards.
     Phone first, centred at ~640px on a desktop. `current` is the tab that is open: profile | security. --}}
@props(['current', 'subtitle'])
<div class="mx-auto flex min-h-dvh w-full max-w-[640px] flex-col gap-6 px-5 pb-12">
    @include('partials.account-header')

    <main class="flex flex-col gap-6">
        <div class="flex flex-col gap-1">
            <h1 class="font-display text-[clamp(36px,6vw,48px)] font-extrabold leading-tight">{{ __('Settings') }}</h1>
            <p class="text-lg text-ink-muted">{{ $subtitle }}</p>
        </div>

        <x-lamma.segmented
            :label="__('Settings')"
            :selected="$current"
            :options="[
                'profile' => ['label' => __('Profile'), 'href' => route('profile.edit')],
                'security' => ['label' => __('Security'), 'href' => route('security.edit')],
            ]"
        />

        {{ $slot }}
    </main>
</div>
