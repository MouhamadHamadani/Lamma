{{-- tone: white (outlined) | coral | sun | teal | navy (soft tints) | line. size: sm 34 | md 36 | lg 40. Plain pill: no sticker. --}}
@props(['tone' => 'white', 'size' => 'md', 'icon' => null])
<span {{ $attributes->class([
    'inline-flex items-center gap-2 whitespace-nowrap rounded-chip px-3.5 font-bold',
    'h-[34px] text-[13px]' => $size === 'sm',
    'h-9 text-sm' => $size === 'md',
    'h-10 text-[15px]' => $size === 'lg',
    'border-2 border-line bg-white' => $tone === 'white',
    'bg-tint-coral' => $tone === 'coral',
    'bg-tint-sun' => $tone === 'sun',
    'bg-tint-teal' => $tone === 'teal',
    'bg-tint-navy' => $tone === 'navy',
    'bg-line' => $tone === 'line',
]) }}>
    @if ($icon)
        <x-lamma.icon :name="$icon" :size="18" />
    @endif
    {{ $slot }}
</span>
