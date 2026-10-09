{{-- One-time code field: six digits, numeric keypad, one-time-code autofill, paste works (non-digits are dropped). Always left to right.
     Bind it with wire:model or x-model like any input. --}}
@props(['name' => 'code', 'label'])
@php
    $id = $attributes->get('id', 'field-'.$name);
    $error = isset($errors) ? $errors->first($name) : null; // $errors is shared by the web middleware, not present when rendered elsewhere
@endphp
<div class="flex flex-col gap-2">
    <label for="{{ $id }}" class="text-[15px] font-semibold">{{ $label }}</label>

    <input
        id="{{ $id }}" name="{{ $name }}" type="text" lang="en" dir="ltr"
        inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" spellcheck="false"
        x-on:paste.prevent="$el.value = ($event.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6); $el.dispatchEvent(new Event('input', { bubbles: true }))"
        @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->except('id')->class([
            'h-16 w-full rounded-input border-2 bg-white px-4 text-center font-display text-3xl font-extrabold tracking-[.5em] placeholder:text-line-strong',
            'border-line' => ! $error,
            'border-coral-700' => $error,
        ]) }}
    >

    @if ($error)
        <x-lamma.error id="{{ $id }}-error">{{ $error }}</x-lamma.error>
    @endif
</div>
