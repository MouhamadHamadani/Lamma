{{-- Answer tile. index 0-3 = A coral triangle, B teal circle, C sun square, D navy diamond: the shape is required, colour alone never tells answers apart.
     size: host (display tile, 2x2 grid) | phone (tap target button) | vote (host, Vote mode: a tall upright tile, three across). state: default | selected | locked | correct | faded.
     Host tiles take an optional `pickers` slot (mini avatars on reveal). `textAlt` is the other-language line (host, Both mode).
     label: the accessible name (default "Answer A: …"); badge: the text of the correct state's badge (default "Correct"; a vote's winner says "Winner");
     count: a live number in a chip on the tile's top corner (the votes so far).
     tall: a true/false tile (two of them, so each is much bigger: host about 26 dvh, phone 128px). compact: a host tile under a picture (about 11 dvh), so the picture fits
     without the screen scrolling; the text stays the same size. --}}
@props(['index' => 0, 'text', 'textAlt' => null, 'altLang' => 'ar', 'lang' => null, 'size' => 'host', 'state' => 'default', 'label' => null, 'badge' => null, 'count' => null, 'tall' => false, 'compact' => false])
@php
    $i = $index % 4;
    $dark = $i === 3;
    $phone = $size === 'phone';
    $vote = $size === 'vote';
    $locked = $phone && $state === 'locked';
    $tag = $phone && ! $locked ? 'button' : 'div';
    $raised = $state === 'correct' || $locked; // the big shadow
    $reveal = in_array($state, ['correct', 'faded'], true); // smaller type, so the pickers and both languages still fit
    $host = ! $phone && ! $vote;
    // How tall a host tile is: a share of the screen's height, so nothing scrolls from 1280x720 up.
    $hostHeight = match (true) {
        $compact && $reveal => 'h-[clamp(64px,10dvh,100px)]',
        $compact => 'h-[clamp(72px,11.5dvh,112px)]',
        $tall && $reveal => 'h-[clamp(110px,22dvh,220px)]',
        $tall => 'h-[clamp(130px,26dvh,260px)]',
        $reveal => 'h-[clamp(80px,13.8dvh,124px)]',
        default => 'h-[clamp(96px,16.67dvh,150px)]',
    };
@endphp
<{{ $tag }}
    @if ($tag === 'button') type="button" @disabled(in_array($state, ['correct', 'faded'], true)) aria-pressed="{{ $state === 'selected' ? 'true' : 'false' }}" @else role="group" @endif
    aria-label="{{ $label ?? __('Answer :letter: :text', ['letter' => chr(65 + $i), 'text' => $text]) }}"
    {{-- The vote screen bursts its winner's confetti itself, after a tie's shuffle. --}}
    @if ($state === 'correct' && ! $phone && ! $vote) x-init="$nextTick(() => $el._burst ||= (lammaConfetti.burst($el, 18), true))" @endif
    @if ($state === 'default' && ! $phone) style="animation-delay: {{ $i * 60 }}ms" @endif
    {{ $attributes->class([
        'relative flex border-3 border-navy',
        ['bg-coral text-navy', 'bg-teal text-navy', 'bg-sun text-navy', 'bg-navy text-cream'][$i],
        $state === 'selected' ? 'translate-x-0.5 translate-y-0.5 shadow-[1px_1px_0_var(--color-navy)]' : ($dark ? ($raised ? 'shadow-sticker-dark-lg' : 'shadow-sticker-dark') : ($raised ? 'shadow-sticker-lg' : 'shadow-sticker')),
        // host tile: 150px tall (124px on reveal), shrinks with the screen height so nothing scrolls
        'items-center rounded-tile px-7 font-display font-bold' => ! $phone && ! $vote,
        'gap-5' => ! $phone && ! $vote && ! $reveal,
        'gap-4' => ! $phone && ! $vote && $reveal,
        $hostHeight => $host,
        'text-[clamp(24px,2.5vw,40px)] rtl:text-[clamp(20px,1.95vw,32px)]' => $host && ! $reveal && ! $tall && ! $compact,
        // (a tile under a picture is short, so its words are the smaller size too: a long answer still fits on two lines)
        'text-[clamp(20px,2.1vw,34px)] leading-tight rtl:text-[clamp(18px,1.7vw,28px)]' => $host && ($reveal || $compact) && ! $tall,
        'text-[clamp(30px,3.6vw,56px)] rtl:text-[clamp(26px,3vw,46px)]' => $host && ! $reveal && ! $compact && $tall,
        'text-[clamp(26px,3vw,48px)] leading-tight rtl:text-[clamp(22px,2.5vw,40px)]' => $host && ($reveal || $compact) && $tall,
        // vote tile: upright, the shape over the name, as tall as a third of the screen
        'h-[clamp(170px,36dvh,330px)] flex-col items-center justify-center gap-[clamp(8px,1.5dvh,14px)] rounded-card px-6 text-balance text-center font-display text-[clamp(26px,2.8vw,46px)] font-extrabold leading-tight rtl:text-[clamp(22px,2.3vw,38px)]' => $vote,
        // phone tile: 88px (72px on short screens), 44px shape well
        'h-22 w-full items-center gap-4 rounded-[20px] px-5 text-start font-display text-[22px] font-bold rtl:text-2xl [@media(max-height:700px)]:h-18' => $phone && ! $locked && ! $tall,
        'h-32 w-full items-center gap-4 rounded-[22px] px-6 text-start font-display text-[30px] font-extrabold rtl:text-[32px] [@media(max-height:700px)]:h-24' => $phone && ! $locked && $tall,
        'sticker-press' => $phone && ! $locked && $state !== 'faded' && $state !== 'correct',
        // locked: the chosen answer, big and tilted
        'min-h-[190px] w-[260px] -rotate-3 flex-col items-center justify-center gap-3.5 rounded-card px-4 py-5 text-balance text-center font-display text-[40px] font-extrabold leading-tight' => $locked,
        'opacity-35 transition-opacity duration-300' => $state === 'faded',
        'motion-safe:animate-reveal' => $state === 'correct',
        'motion-safe:animate-pop-in motion-reduce:animate-fade-in' => $state === 'default' && ! $phone,
    ]) }}
>
    @if ($count !== null)
        {{-- Keyed by the number, so a new count pops in. --}}
        <span wire:key="count-{{ $i }}-{{ $count }}" dir="ltr" class="absolute -top-[18px] start-5 flex h-10 min-w-10 items-center justify-center rounded-chip border-3 border-navy bg-white px-2.5 font-display text-xl font-extrabold text-navy shadow-sticker-sm motion-safe:animate-chip-pop" data-test="vote-count">{{ $count }}</span>
    @endif

    @if ($state === 'correct' && ! $phone)
        {{-- A ring grows out of the tile and fades. --}}
        <span data-flourish aria-hidden="true" class="pointer-events-none absolute -inset-1.5 rounded-[28px] border-3 border-navy motion-safe:animate-ring-pulse"></span>
        <span data-flourish class="absolute -top-[18px] end-5 flex h-9 items-center gap-1.5 rounded-chip border-3 border-navy bg-white px-3.5 text-[15px] font-extrabold text-navy shadow-sticker-sm motion-safe:animate-drop-in">
            <x-lamma.icon name="check" :size="18" :stroke="3" />
            {{ $badge ?? __('Correct') }}
        </span>
    @endif

    @if ($phone && ! $locked)
        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/35">
            <x-lamma.shape :index="$i" :size="22" />
        </span>
    @else
        <x-lamma.shape :index="$i" :size="$locked || $vote ? 44 : 32" />
    @endif

    <span @class(['min-w-0 grow' => ! $locked && ! $vote]) @if ($lang) lang="{{ $lang }}" @endif>{{ $text }}</span>

    @if ($vote && $textAlt)
        <span lang="{{ $altLang }}" dir="{{ $altLang === 'ar' ? 'rtl' : 'ltr' }}" class="text-[clamp(18px,1.9vw,30px)] font-bold opacity-85">{{ $textAlt }}</span>
    @endif

    @isset ($pickers)
        {{-- On a big true/false tile the avatars stand in its bottom corner, under the words, where there is room; elsewhere they follow the text. --}}
        <span @class(['flex shrink-0 -space-x-2.5 rtl:space-x-reverse', 'absolute bottom-3 end-5' => $tall && ! $compact && ! $phone])>{{ $pickers }}</span>
    @endisset

    @if ($textAlt && ! $phone && ! $vote)
        <span lang="{{ $altLang }}" dir="{{ $altLang === 'ar' ? 'rtl' : 'ltr' }}" @class(['min-w-0 max-w-[40%] opacity-85', 'text-[clamp(20px,1.95vw,32px)]' => ! $reveal, 'text-[clamp(16px,1.6vw,26px)] leading-tight' => $reveal])>{{ $textAlt }}</span>
    @endif
</{{ $tag }}>
