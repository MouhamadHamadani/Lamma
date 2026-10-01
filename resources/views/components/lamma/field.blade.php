{{-- Labelled text input with an inline error (coral-700 + icon, so it never relies on colour alone). Plain 2px line border, no sticker.
     Email and password fields are always ltr. `viewable` adds a show/hide toggle. The slot sits under the input (e.g. "Forgot password?"). --}}
@props(['name', 'label', 'type' => 'text', 'value' => null, 'viewable' => false])
@php
    $id = $attributes->get('id', 'field-'.$name);
    $error = isset($errors) ? $errors->first($name) : null; // $errors is shared by the web middleware, not present when rendered elsewhere
    $secret = $type === 'password';
    $ltr = $secret || $type === 'email';
@endphp
<div class="flex flex-col gap-2">
    <label for="{{ $id }}" class="text-[15px] font-semibold">{{ $label }}</label>

    <div class="relative" @if ($ltr) dir="ltr" @endif @if ($viewable) x-data="{ show: false }" @endif>
        <input
            id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
            @if (! $secret) value="{{ old($name, $value) }}" @endif
            @if ($viewable) x-bind:type="show ? 'text' : 'password'" @endif
            @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            {{ $attributes->except('id')->class([
                'h-14 w-full rounded-input border-2 bg-white px-[18px] text-[17px] placeholder:text-ink-subtle',
                'border-line' => ! $error,
                'border-coral-700' => $error,
                'pe-14' => $viewable,
            ]) }}
        >
        @if ($viewable)
            <button
                type="button" x-on:click="show = ! show"
                x-bind:aria-label="show ? {{ Js::from(__('Hide password')) }} : {{ Js::from(__('Show password')) }}"
                aria-label="{{ __('Show password') }}"
                class="absolute end-1 top-1 flex size-11 items-center justify-center rounded-input text-ink-muted hover:bg-tint-navy"
            >
                <x-lamma.icon name="eye" x-show="! show" />
                <x-lamma.icon name="eye-off" x-show="show" x-cloak />
            </button>
        @endif
    </div>

    @if ($error)
        <x-lamma.error id="{{ $id }}-error">{{ $error }}</x-lamma.error>
    @endif

    {{ $slot }}
</div>
