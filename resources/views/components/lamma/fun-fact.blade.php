{{-- The fun fact shown with the answer: a bulb and one or two short lines. `text` is the main line (in `lang`); `alt` is the Arabic line under it when the host screen
     shows both languages. size: host (compact, the reveal has little room) | phone (with a visible "Fun fact" caption). Each line is cut at two lines (three on a phone). --}}
@props(['text', 'alt' => null, 'lang' => null, 'size' => 'host'])
<aside {{ $attributes->class([
    'flex items-start gap-3 rounded-tile border-2 border-line bg-white',
    'px-4 py-2.5' => $size === 'host',
    'px-[18px] py-3.5 text-start' => $size === 'phone',
]) }} data-test="fun-fact">
    <span class="flex shrink-0 items-center justify-center rounded-full bg-tint-sun {{ $size === 'host' ? 'size-9' : 'size-10' }}"><x-lamma.icon name="bulb" :size="20" /></span>
    <div class="flex min-w-0 grow flex-col gap-0.5">
        <span @class(['text-[13px] font-bold text-ink-subtle', 'sr-only' => $size === 'host'])>{{ __('Fun fact') }}</span>
        <p @if ($lang) lang="{{ $lang }}" @endif @class([
            'font-semibold leading-snug',
            'line-clamp-2 text-[clamp(15px,1.45vw,21px)] rtl:text-[clamp(15px,1.35vw,19px)]' => $size === 'host',
            'line-clamp-3 text-base' => $size === 'phone',
        ])>{{ $text }}</p>
        @if ($alt)
            <p lang="ar" dir="rtl" class="line-clamp-2 text-end text-[clamp(14px,1.3vw,19px)] font-semibold leading-snug text-ink-muted">{{ $alt }}</p>
        @endif
    </div>
</aside>
