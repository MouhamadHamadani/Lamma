{{-- Pick one. options: value => label, or value => ['label' => ..., 'lang' => 'ar'].
     Bind with wire:model (add .live to sync instantly), or pass `selected` for a standalone control. --}}
@props(['options', 'selected' => null, 'label' => null])
@php
    $model = $attributes->wire('model');
    $state = $model->value()
        ? '$wire.$entangle('.Js::from($model->value()).($model->hasModifier('live') ? ', true' : '').')'
        : Js::from($selected);
@endphp
<div
    x-data="{ value: {!! $state !!} }"
    role="group" @if ($label) aria-label="{{ $label }}" @endif
    {{ $attributes->whereDoesntStartWith('wire:model')->class('flex gap-1 rounded-btn border-2 border-line bg-cream p-[5px]') }}
>
    @foreach ($options as $value => $option)
        @php [$text, $lang] = is_array($option) ? [$option['label'], $option['lang'] ?? null] : [$option, null]; @endphp
        <button
            type="button"
            @if ($lang) lang="{{ $lang }}" @endif
            x-on:click="value = {{ Js::from($value) }}"
            x-bind:aria-pressed="String(value) === {{ Js::from((string) $value) }}"
            x-bind:class="String(value) === {{ Js::from((string) $value) }} ? 'bg-navy font-bold text-cream' : 'font-semibold text-navy'"
            class="h-12 grow rounded-xl text-base"
        >{{ $text }}</button>
    @endforeach
</div>
