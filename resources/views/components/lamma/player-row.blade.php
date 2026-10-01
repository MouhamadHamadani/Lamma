{{-- One <li> per player. `player` is a RoomPlayer or array (nickname, locale, is_ready, left_at). size: host (76px card) | phone (56px, for a list
     inside a card). index picks the avatar colour; `you` appends "(you)". A player whose phone is disconnected (left_at set) is greyed out.
     An `actions` slot sits at the end of the row (the host's remove button). --}}
@props(['player', 'index' => 0, 'size' => 'host', 'showLanguage' => true, 'showReady' => true, 'you' => false])
@php
    $name = data_get($player, 'nickname');
    $locale = data_get($player, 'locale');
    $host = $size === 'host';
    $disconnected = filled(data_get($player, 'left_at'));
@endphp
<li {{ $attributes->class([
    'flex items-center motion-safe:animate-row-in motion-reduce:animate-fade-in',
    'h-19 gap-3.5 rounded-[20px] border-2 border-line bg-white px-[18px]' => $host,
    'h-14 gap-3 border-b border-dashed border-line last:border-b-0' => ! $host,
]) }} @if ($disconnected) data-disconnected @endif>
    <span @class(['flex min-w-0 grow items-center', 'gap-3.5' => $host, 'gap-3' => ! $host, 'opacity-45' => $disconnected])>
        <x-lamma.avatar :name="$name" :color="$index" :size="$host ? 48 : 36" />

        <span @class(['grow truncate', 'font-display text-2xl font-bold' => $host, 'text-base font-bold' => ! $host])>
            <bdi dir="ltr">{{ $name }}</bdi>
            @if ($you)
                <span class="font-medium text-ink-subtle">{{ __('(you)') }}</span>
            @endif
        </span>

        @if ($showLanguage)
            <span
                lang="{{ $locale }}"
                class="flex h-7 items-center rounded-lg bg-tint-navy px-2.5 text-[13px] font-bold"
            >{{ $locale === 'ar' ? 'ع' : strtoupper($locale) }}</span>
        @endif
    </span>

    @if ($showReady)
        <x-lamma.status-pill :ready="(bool) data_get($player, 'is_ready')" :disconnected="$disconnected" :size="$host ? 'md' : 'sm'" />
    @endif

    @isset ($actions)
        {{ $actions }}
    @endisset
</li>
