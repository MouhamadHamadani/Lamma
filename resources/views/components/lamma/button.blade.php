{{-- variant: primary (coral sticker) | teal (an "on" toggle, e.g. Ready) | dark | sun (CTA on navy) | outline | danger (outline in coral-700, destructive) | ghost. Disabled always shows the dashed lock look. --}}
@props(['variant' => 'primary', 'size' => 'md', 'icon' => null, 'href' => null, 'type' => 'button', 'onDark' => false, 'disabled' => false])
@php
    $disabled = (bool) $disabled;
    $tag = $href && ! $disabled ? 'a' : 'button';
    $sticker = 'border-3 border-navy sticker-press';
@endphp
<{{ $tag }}
    @if ($tag === 'a') href="{{ $href }}" @else type="{{ $type }}" @disabled($disabled) @endif
    {{ $attributes->class([
        'inline-flex items-center justify-center gap-2.5 font-bold transition-colors',
        'rounded-btn px-7' => $variant !== 'ghost',
        'min-h-14 text-[17px]' => $size === 'md' && $variant !== 'ghost',
        'min-h-16 text-[19px]' => $size === 'lg' && $variant !== 'ghost',
        'min-h-11 px-2 font-semibold' => $variant === 'ghost',
        'cursor-not-allowed border-3 border-dashed border-line-strong bg-cream text-ink-subtle' => $disabled,
        "$sticker bg-coral text-navy" => ! $disabled && $variant === 'primary',
        'shadow-sticker' => ! $disabled && $variant === 'primary' && ! $onDark,
        'shadow-sticker-dark' => ! $disabled && $variant === 'primary' && $onDark,
        "$sticker bg-teal text-navy shadow-sticker" => ! $disabled && $variant === 'teal',
        "$sticker bg-navy text-cream shadow-sticker-coral-sm" => ! $disabled && $variant === 'dark',
        "$sticker bg-sun text-navy shadow-sticker-coral" => ! $disabled && $variant === 'sun',
        'border-3 bg-transparent' => ! $disabled && in_array($variant, ['outline', 'danger']),
        'border-coral-700 text-coral-700 hover:bg-tint-coral' => ! $disabled && $variant === 'danger',
        'border-navy text-navy hover:bg-tint-navy' => ! $disabled && $variant === 'outline' && ! $onDark,
        'border-cream text-cream hover:bg-white/10' => ! $disabled && $variant === 'outline' && $onDark,
        'hover:underline underline-offset-4' => ! $disabled && $variant === 'ghost',
        'text-navy' => ! $disabled && $variant === 'ghost' && ! $onDark,
        'text-cream' => ! $disabled && $variant === 'ghost' && $onDark,
    ]) }}
>
    @if ($disabled)
        <x-lamma.icon name="lock" :size="20" />
    @elseif ($icon)
        <x-lamma.icon :name="$icon" :size="20" :stroke="2.4" />
    @endif
    {{ $slot }}
</{{ $tag }}>
