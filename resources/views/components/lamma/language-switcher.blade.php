{{-- Language toggle: shows the OTHER language ("عربي" on an English page, "EN" on an Arabic one) and links to locale.switch.
     A full reload (not wire:navigate) so <html lang dir> flips with it. --}}
@foreach (config('locales.supported') as $code => $locale)
    @unless ($code === app()->getLocale())
        <a
            href="{{ route('locale.switch', $code) }}"
            lang="{{ $code }}"
            hreflang="{{ $code }}"
            aria-label="{{ $locale['name'] }}"
            data-test="locale-switch-{{ $code }}"
            {{ $attributes->class('inline-flex h-11 items-center rounded-chip border border-line bg-white px-4 text-sm font-semibold text-navy hover:bg-tint-navy') }}
        >{{ $locale['short'] }}</a>
    @endunless
@endforeach
