{{-- A room code that no longer exists (/host/XXXX, /play/XXXX) gets its own words and a Join a game button. --}}
@if (request()->route()?->hasParameter('room'))
    <x-layouts::error code="404" :title="__('That room doesn’t exist')" :message="__('The room code may be wrong, or the host has closed the game.')">
        <x-lamma.button :href="route('join')" icon="play">{{ __('Join a game') }}</x-lamma.button>
        <x-lamma.button :href="route('home')" variant="outline">{{ __('Back to home') }}</x-lamma.button>
    </x-layouts::error>
@else
    <x-layouts::error code="404" :title="__('Page not found')" :message="__('The page you’re looking for doesn’t exist or has moved.')">
        <x-lamma.button :href="route('home')" icon="home">{{ __('Back to home') }}</x-lamma.button>
    </x-layouts::error>
@endif
