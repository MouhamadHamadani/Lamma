{{-- Maintenance. `php artisan down --render="errors::503"` renders this once and serves the result to everyone, so it speaks both
     languages (Arabic first) and carries its own CSS (see layouts/error.blade.php). --}}
@php
    $ar = fn (string $key) => __($key, [], 'ar');
    $en = fn (string $key) => __($key, [], 'en');
@endphp
<x-layouts::error code="503" tone="teal" bilingual inline :title="$ar('We’ll be right back')" :message="$ar('Lamma is being updated. Try again in a minute.')">
    <x-slot:extra>
        <div class="mt-4 flex flex-col gap-1" lang="en" dir="ltr">
            <p class="font-display text-2xl font-extrabold">{{ $en('We’ll be right back') }}</p>
            <p class="text-ink-muted">{{ $en('Lamma is being updated. Try again in a minute.') }}</p>
        </div>
    </x-slot:extra>

    <x-lamma.button :href="route('home')" icon="home">{{ $ar('Back to home') }} · {{ $en('Back to home') }}</x-lamma.button>
</x-layouts::error>
