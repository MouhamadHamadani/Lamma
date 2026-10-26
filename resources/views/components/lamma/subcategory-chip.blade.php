{{-- A subcategory you can switch on or off: 44px tall, aria-pressed. `category` is a Category or array (slug, name); `tint` is the parent's tint
     class (bg-tint-sun ...). Wire it up from the page: wire:click="toggleSubcategory({{ $id }})". --}}
@props(['category', 'selected' => false, 'tint' => 'bg-tint-navy'])
@php
    $icon = \App\Support\CategoryStyle::icon((string) data_get($category, 'slug'));
@endphp
<button
    type="button" aria-pressed="{{ $selected ? 'true' : 'false' }}"
    {{ $attributes->class([
        'inline-flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-chip px-4 text-[15px] font-bold',
        "border-2 border-navy $tint" => $selected,
        'border-2 border-line bg-white' => ! $selected,
    ]) }}
>
    <x-lamma.icon :name="$icon" :size="18" />
    {{ data_get($category, 'name') }}
    @if ($selected)
        <x-lamma.icon name="check" :size="16" :stroke="3" />
    @endif
</button>
