<x-layouts::error code="401" :title="__('Log in to continue')" :message="__('You need to be logged in to see this page.')">
    <x-lamma.button :href="route('login')" icon="key">{{ __('Log in') }}</x-lamma.button>
    <x-lamma.button :href="route('home')" variant="outline">{{ __('Back to home') }}</x-lamma.button>
</x-layouts::error>
