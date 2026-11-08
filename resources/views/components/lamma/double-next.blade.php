{{-- "Next: double points": on the reveal of a game's SECOND-TO-LAST question, so nobody is surprised that the last one counts double. The x2 sticker (like <x-lamma.double-chip>)
     and a short line in a sun-tinted pill. size: md (phone) | lg (big screen). lang: the language of the line, the screen's own (on the big screen in Both that is English, like the
     rest of its labels: the x2 sticker needs no translation). The live region of the screen says it as well ("Next: double points"); this is the visual half, so the sticker is
     hidden from screen readers and the words are not. --}}
@props(['size' => 'md', 'lang' => null])
@php $lang ??= app()->getLocale(); @endphp
<span {{ $attributes->class([
    'inline-flex max-w-full items-center rounded-chip border-3 border-navy bg-tint-sun font-display font-extrabold leading-none text-navy shadow-sticker-sm motion-safe:animate-chip-pop motion-reduce:animate-fade-in',
    'gap-2 py-1 ps-1 pe-3 text-[15px]' => $size === 'md',
    'gap-3 py-1.5 ps-1.5 pe-5 text-xl' => $size === 'lg',
]) }} lang="{{ $lang }}" data-test="double-next">
    <span dir="ltr" aria-hidden="true" @class([
        'inline-flex shrink-0 -rotate-3 items-center rounded-chip border-3 border-navy bg-coral font-display font-extrabold leading-none text-navy shadow-sticker-sm',
        'h-7 px-2 text-sm' => $size === 'md',
        'h-9 px-2.5 text-xl' => $size === 'lg',
    ])>x2</span>
    <span class="text-balance">{{ __('Next: double points', [], $lang) }}</span>
</span>
