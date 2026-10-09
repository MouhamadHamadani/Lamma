{{-- Any other client error (401, 405, 408, ...): the same page with a generic line. --}}
<x-layouts::error :code="isset($exception) ? $exception->getStatusCode() : 400" tone="sun" :title="__('That didn’t work')" :message="__('Something about that request wasn’t right.')">
    <x-lamma.button :href="route('home')" icon="home">{{ __('Back to home') }}</x-lamma.button>
</x-layouts::error>
