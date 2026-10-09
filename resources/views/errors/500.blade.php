<x-layouts::error code="500" tone="coral" :title="__('Something went wrong')" :message="__('It’s on us. Please try again in a moment.')">
    <x-lamma.button :href="route('home')" icon="home">{{ __('Back to home') }}</x-lamma.button>
</x-layouts::error>
