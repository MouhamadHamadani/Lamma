{{-- Links to every supported locale except the current one (a full reload resets dir/lang). --}}
@foreach (config('locales.supported') as $code => $locale)
    @unless ($code === app()->getLocale())
        <flux:button
            {{ $attributes }}
            variant="ghost"
            size="sm"
            icon="language"
            :href="route('locale.switch', $code)"
            lang="{{ $code }}"
            data-test="locale-switch-{{ $code }}"
        >
            {{ $locale['name'] }}
        </flux:button>
    @endunless
@endforeach
