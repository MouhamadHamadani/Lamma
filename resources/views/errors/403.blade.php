<x-layouts::error code="403" tone="coral" :title="__('You can’t go here')" :message="__('You don’t have permission to see this page.')">
    <x-lamma.button :href="route('home')" icon="home">{{ __('Back to home') }}</x-lamma.button>
</x-layouts::error>
