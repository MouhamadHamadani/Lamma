{{-- The navy scoreboard panel on the host's reveal screen. `ranking` is Scoreboard::ranking(); `previous` maps player id => where they stood
     before this round, so each row slides from there to its new place (FLIP, 400ms) while the "+100" counts up (`gained` false hides it,
     e.g. for the final standings). The leader is lighter with a sun rank number. Nothing on the host scrolls, so how many rows show depends
     on the screen height: 5 from 720px tall, up to 10 on a 1080px screen. The slot is the footer (the "Next question" countdown and button). --}}
@props(['ranking', 'previous' => [], 'colors' => [], 'gained' => true])
@php
    // index => the screen height (px) from which that row fits; the first five always show
    $fromHeight = [5 => '[@media(min-height:800px)]:flex', 6 => '[@media(min-height:880px)]:flex', 7 => '[@media(min-height:940px)]:flex', 8 => '[@media(min-height:1000px)]:flex', 9 => '[@media(min-height:1060px)]:flex'];
@endphp
<aside {{ $attributes->class('flex min-h-0 flex-col gap-3.5 rounded-card border-3 border-navy bg-navy p-6 text-cream shadow-sticker-dark-lg') }} aria-labelledby="scoreboard-title">
    <h2 id="scoreboard-title" class="font-display text-[28px] font-extrabold">{{ __('Scoreboard') }}</h2>

    <ol class="flex min-h-0 grow flex-col gap-2.5 overflow-hidden [--row:56px]" data-test="scoreboard">
        @foreach (array_slice($ranking, 0, 10) as $index => $row)
            @php $id = $row['player']['id']; @endphp
            <li
                style="--dy: calc((var(--row) + 10px) * {{ ($previous[$id] ?? $index) - $index }})" data-player="{{ $id }}" data-rank="{{ $row['rank'] }}"
                @class([
                    'h-(--row) shrink-0 items-center gap-3 rounded-2xl px-3.5 motion-safe:animate-flip motion-reduce:animate-fade-in',
                    $index < 5 ? 'flex' : 'hidden '.$fromHeight[$index],
                    'bg-navy-600' => $row['rank'] === 1,
                    'bg-navy-700' => $row['rank'] !== 1,
                ])
            >
                <span dir="ltr" @class(['w-6 text-center font-display text-xl font-extrabold', 'text-sun' => $row['rank'] === 1, 'text-ink-on-dark' => $row['rank'] !== 1])>{{ $row['rank'] }}</span>
                <x-lamma.avatar :name="$row['player']['nickname']" :color="$colors[$id] ?? $index" :size="36" />
                <span class="grow truncate text-[17px] font-bold"><bdi dir="ltr">{{ $row['player']['nickname'] }}</bdi></span>
                <span dir="ltr" class="text-end">
                    <span class="block font-display text-xl font-extrabold leading-none" data-test="total">{{ $row['total'] }}</span>
                    @if ($gained)
                        <span @class(['block text-xs font-bold', 'text-teal' => $row['gained'] > 0, 'text-ink-on-dark/70' => $row['gained'] === 0]) data-test="gained" x-data="lammaCountUp({{ $row['gained'] }})">+<span x-text="n">{{ $row['gained'] }}</span></span>
                    @endif
                </span>
            </li>
        @endforeach
    </ol>

    {{ $slot }}
</aside>
