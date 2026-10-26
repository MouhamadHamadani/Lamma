{{-- Multi-select category tile. `category` is a Category or array (slug, name). The icon comes from the slug (no emoji), the tint is stable per slug.
     Wire it up from the page: wire:click="toggleCategory({{ $id }})".
     state: off | partial (some subcategories chosen: aria-pressed="mixed", a dash instead of the check) | all; `selected` is the old on/off shorthand.
     A category with subcategories passes `expand` (attributes for the chevron button, e.g. ['wire:click' => 'toggleExpanded(3)']), `expanded` and the id of the
     panel it opens in `panel`; `subtitle` is the small line under the name ("2 of 7"). --}}
@props(['category', 'selected' => false, 'state' => null, 'subtitle' => null, 'expand' => null, 'expanded' => false, 'panel' => null])
@php
    $state ??= $selected ? 'all' : 'off';
    $slug = (string) data_get($category, 'slug');
    $icon = \App\Support\CategoryStyle::icon($slug);
    $tint = \App\Support\CategoryStyle::tint($slug);
    $name = data_get($category, 'name');
@endphp
<div @class([
    'flex h-18 laptop-short:h-14 w-full items-stretch rounded-[18px]',
    "sticker-sm $tint" => $state === 'all',
    'border-3 border-navy bg-white' => $state === 'partial',
    'border-2 border-line bg-white' => $state === 'off',
])>
    <button
        type="button" aria-pressed="{{ ['all' => 'true', 'partial' => 'mixed', 'off' => 'false'][$state] }}"
        {{ $attributes->class('flex min-w-0 grow items-center gap-3 rounded-[16px] ps-3.5 text-start') }}
    >
        <span @class(['flex size-11 shrink-0 items-center justify-center rounded-[14px]', 'bg-white' => $state === 'all', $tint => $state !== 'all'])>
            <x-lamma.icon :name="$icon" />
        </span>
        <span class="flex min-w-0 grow flex-col">
            <span class="font-display text-[19px] font-bold leading-tight">{{ $name }}</span>
            @if ($subtitle)
                <span class="text-[13px] font-semibold leading-tight text-ink-muted">{{ $subtitle }}</span>
            @endif
        </span>
        @if ($state === 'all')
            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-navy text-cream">
                <x-lamma.icon name="check" :size="16" :stroke="3" />
            </span>
        @elseif ($state === 'partial')
            <span class="flex size-7 shrink-0 items-center justify-center rounded-full border-2 border-navy text-navy">
                <x-lamma.icon name="minus" :size="16" :stroke="3" />
            </span>
        @else
            <span class="size-6 shrink-0 rounded-full border-2 border-line-strong"></span>
        @endif
    </button>

    @if ($expand !== null)
        <button
            type="button" aria-expanded="{{ $expanded ? 'true' : 'false' }}" @if ($panel) aria-controls="{{ $panel }}" @endif
            aria-label="{{ __('Subcategories of :category', ['category' => $name]) }}"
            {{ (new \Illuminate\View\ComponentAttributeBag($expand))->class('flex w-11 shrink-0 items-center justify-center rounded-e-[16px] text-navy') }}
        >
            <x-lamma.icon name="chevron-down" :size="22" :stroke="2.4" :class="$expanded ? 'rotate-180 motion-safe:transition-transform' : 'motion-safe:transition-transform'" />
        </button>
    @else
        <span class="w-3.5 shrink-0"></span>
    @endif
</div>
