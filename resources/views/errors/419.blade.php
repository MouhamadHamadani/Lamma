<x-layouts::error code="419" tone="teal" :title="__('Page expired')" :message="__('Your session timed out. Refresh and try again.')">
    <x-lamma.button :href="\App\Support\ErrorPage::backUrl(request())" icon="refresh">{{ __('Refresh and try again') }}</x-lamma.button>
    <x-lamma.button :href="route('home')" variant="outline">{{ __('Back to home') }}</x-lamma.button>
</x-layouts::error>
