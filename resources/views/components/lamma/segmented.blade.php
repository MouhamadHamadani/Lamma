{{-- Pick one. options: value => label, or value => ['label' => ..., 'lang' => 'ar', 'href' => '/x'].
     Bind with wire:model (add .live to sync instantly), or pass `selected` for a standalone control.
     An option with an `href` renders as a link (aria-current) instead of a button, e.g. the Log in / Sign up switch between pages. --}}
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
        @php [$text, $lang, $href] = is_array($option) ? [$option['label'], $option['lang'] ?? null, $option['href'] ?? null] : [$option, null, null]; @endphp
        @if ($href)
            @php $current = (string) $value === (string) $selected; @endphp
            <a
                href="{{ $href }}"
                @if ($lang) lang="{{ $lang }}" @endif
                @if ($current) aria-current="page" @endif
                @class([
                    'flex h-12 grow items-center justify-center rounded-xl text-base',
                    'bg-navy font-bold text-cream' => $current,
                    'font-semibold text-navy hover:bg-tint-navy' => ! $current,
                ])
            >{{ $text }}</a>
        @else
            <button
                type="button"
                @if ($lang) lang="{{ $lang }}" @endif
                x-on:click="value = {{ Js::from($value) }}"
                x-bind:aria-pressed="String(value) === {{ Js::from((string) $value) }}"
                x-bind:class="String(value) === {{ Js::from((string) $value) }} ? 'bg-navy font-bold text-cream' : 'font-semibold text-navy'"
                class="h-12 grow rounded-xl text-base"
            >{{ $text }}</button>
        @endif
    @endforeach
</div>
