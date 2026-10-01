{{-- Stand-in for a screen a later phase builds (/join, /rooms/create). Replace the route, then delete this view. --}}
<x-layouts::lamma :title="$title">
    <main class="mx-auto flex min-h-dvh max-w-md flex-col items-center justify-center gap-6 p-6 text-center">
        <a href="{{ route('home') }}"><x-lamma.logo size="lg" /></a>
        <h1 class="font-display text-4xl font-extrabold">{{ $title }}</h1>
        @if ($code)
            <x-lamma.room-code :code="$code" size="chip" />
        @endif
        <p class="text-ink-muted">{{ __('This screen is coming soon.') }}</p>
        <x-lamma.button :href="route('home')" variant="outline">{{ __('Back to home') }}</x-lamma.button>
    </main>
</x-layouts::lamma>
