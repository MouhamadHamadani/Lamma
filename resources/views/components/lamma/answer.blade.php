{{-- Answer tile. index 0-3 = A coral triangle, B teal circle, C sun square, D navy diamond: the shape is required, colour alone never tells answers apart.
     size: host (display tile, 2x2 grid) | phone (tap target button). state: default | selected | locked | correct | faded.
     Host tiles take an optional `pickers` slot (mini avatars on reveal). `textAlt` is the other-language line (host, Both mode). --}}
@props(['index' => 0, 'text', 'textAlt' => null, 'altLang' => 'ar', 'lang' => null, 'size' => 'host', 'state' => 'default'])
@php
    $i = $index % 4;
    $dark = $i === 3;
    $phone = $size === 'phone';
    $locked = $phone && $state === 'locked';
    $tag = $phone && ! $locked ? 'button' : 'div';
    $raised = $state === 'correct' || $locked; // the big shadow
    $reveal = in_array($state, ['correct', 'faded'], true); // smaller type, so the pickers and both languages still fit
@endphp
<{{ $tag }}
    @if ($tag === 'button') type="button" @disabled(in_array($state, ['correct', 'faded'], true)) aria-pressed="{{ $state === 'selected' ? 'true' : 'false' }}" @else role="group" @endif
    aria-label="{{ __('Answer :letter: :text', ['letter' => chr(65 + $i), 'text' => $text]) }}"
    @if ($state === 'default' && ! $phone) style="animation-delay: {{ $i * 60 }}ms" @endif
    {{ $attributes->class([
        'relative flex border-3 border-navy',
        ['bg-coral text-navy', 'bg-teal text-navy', 'bg-sun text-navy', 'bg-navy text-cream'][$i],
        $state === 'selected' ? 'translate-x-0.5 translate-y-0.5 shadow-[1px_1px_0_var(--color-navy)]' : ($dark ? ($raised ? 'shadow-sticker-dark-lg' : 'shadow-sticker-dark') : ($raised ? 'shadow-sticker-lg' : 'shadow-sticker')),
        // host tile: 150px tall (124px on reveal), shrinks with the screen height so nothing scrolls
        'items-center rounded-tile px-7 font-display font-bold' => ! $phone,
        'gap-5' => ! $phone && ! $reveal,
        'gap-4' => ! $phone && $reveal,
        'h-[clamp(96px,16.67dvh,150px)]' => ! $phone && ! in_array($state, ['correct', 'faded'], true),
        'h-[clamp(80px,13.8dvh,124px)]' => ! $phone && in_array($state, ['correct', 'faded'], true),
        'text-[clamp(24px,2.5vw,40px)] rtl:text-[clamp(20px,1.95vw,32px)]' => ! $phone && ! $reveal,
        'text-[clamp(20px,2.1vw,34px)] leading-tight rtl:text-[clamp(18px,1.7vw,28px)]' => ! $phone && $reveal,
        // phone tile: 88px (72px on short screens), 44px shape well
        'h-22 w-full items-center gap-4 rounded-[20px] px-5 text-start font-display text-[22px] font-bold rtl:text-2xl [@media(max-height:700px)]:h-18' => $phone && ! $locked,
        'sticker-press' => $phone && ! $locked && $state !== 'faded' && $state !== 'correct',
        // locked: the chosen answer, big and tilted
        'min-h-[190px] w-[260px] -rotate-3 flex-col items-center justify-center gap-3.5 rounded-card px-4 py-5 text-balance text-center font-display text-[40px] font-extrabold leading-tight' => $locked,
        'opacity-35 transition-opacity duration-300' => $state === 'faded',
        'motion-safe:animate-reveal' => $state === 'correct',
        'motion-safe:animate-pop-in motion-reduce:animate-fade-in' => $state === 'default' && ! $phone,
    ]) }}
>
    @if ($state === 'correct' && ! $phone)
        <span class="absolute -top-[18px] end-5 flex h-9 items-center gap-1.5 rounded-chip border-3 border-navy bg-white px-3.5 text-[15px] font-extrabold text-navy shadow-sticker-sm motion-safe:animate-drop-in">
            <x-lamma.icon name="check" :size="18" :stroke="3" />
            {{ __('Correct') }}
        </span>
    @endif

    @if ($phone && ! $locked)
        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/35">
            <x-lamma.shape :index="$i" :size="22" />
        </span>
    @else
        <x-lamma.shape :index="$i" :size="$locked ? 44 : 32" />
    @endif

    <span @class(['min-w-0 grow' => ! $locked]) @if ($lang) lang="{{ $lang }}" @endif>{{ $text }}</span>

    @isset ($pickers)
        <span class="flex shrink-0 -space-x-2.5 rtl:space-x-reverse">{{ $pickers }}</span>
    @endisset

    @if ($textAlt && ! $phone)
        <span lang="{{ $altLang }}" dir="{{ $altLang === 'ar' ? 'rtl' : 'ltr' }}" @class(['min-w-0 max-w-[40%] opacity-85', 'text-[clamp(20px,1.95vw,32px)]' => ! $reveal, 'text-[clamp(16px,1.6vw,26px)] leading-tight' => $reveal])>{{ $textAlt }}</span>
    @endif
</{{ $tag }}>
