<x-layouts::error code="429" :title="__('Too many attempts')" :message="__('Please wait a moment, then try again.')">
    <x-lamma.button :href="route('home')" icon="home">{{ __('Back to home') }}</x-lamma.button>
</x-layouts::error>
