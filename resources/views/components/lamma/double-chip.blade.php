{{-- The "x2" sticker on a game's last question when it counts double. The same in every language, so the big screen in Both needs no second line. size: md (phone) | lg (host). --}}
@props(['size' => 'md'])
<span {{ $attributes->class([
    'inline-flex shrink-0 -rotate-3 items-center rounded-chip border-3 border-navy bg-coral font-display font-extrabold leading-none text-navy shadow-sticker-sm motion-safe:animate-chip-pop motion-reduce:animate-fade-in',
    'h-8 px-2.5 text-base' => $size === 'md',
    'h-11 px-3.5 text-2xl' => $size === 'lg',
]) }} data-test="double-chip">
    <span dir="ltr" aria-hidden="true">x2</span>
    <span class="sr-only">{{ __('Double points') }}</span>
</span>
