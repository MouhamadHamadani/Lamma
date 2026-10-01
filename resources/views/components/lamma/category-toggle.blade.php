{{-- Multi-select category tile. `category` is a Category or array (slug, name). The icon comes from the slug (no emoji), the tint is stable per slug.
     Wire it up from the page: wire:click="toggleCategory({{ $id }})". --}}
@props(['category', 'selected' => false])
@php
    $slug = (string) data_get($category, 'slug');
    $icon = \App\Support\CategoryStyle::icon($slug);
    $tint = \App\Support\CategoryStyle::tint($slug);
@endphp
<button
    type="button" aria-pressed="{{ $selected ? 'true' : 'false' }}"
    {{ $attributes->class([
        'flex h-18 w-full items-center gap-3 rounded-[18px] px-3.5 text-start',
        "sticker-sm $tint" => $selected,
        'border-2 border-line bg-white' => ! $selected,
    ]) }}
>
    <span @class(['flex size-11 shrink-0 items-center justify-center rounded-[14px]', 'bg-white' => $selected, $tint => ! $selected])>
        <x-lamma.icon :name="$icon" />
    </span>
    <span class="grow font-display text-[19px] font-bold">{{ data_get($category, 'name') }}</span>
    @if ($selected)
        <span class="flex size-7 items-center justify-center rounded-full bg-navy text-cream">
            <x-lamma.icon name="check" :size="16" :stroke="3" />
        </span>
    @else
        <span class="size-6 rounded-full border-2 border-line-strong"></span>
    @endif
</button>
