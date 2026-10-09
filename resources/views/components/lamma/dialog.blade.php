{{-- Confirmation dialog on the native <dialog>: the browser traps focus, makes the page inert and closes on Escape.
     Open:  $dispatch('open-dialog', { name: 'delete-account' })      Close: $dispatch('close-dialog', { name }) or a <button formmethod="dialog">.
     Pass x-on:close="..." to react to it closing (Escape included). wire:ignore.self keeps a Livewire re-render from closing it. --}}
@props(['name', 'title', 'description' => null])
<dialog
    wire:ignore.self
    x-data
    x-on:open-dialog.window="if ($event.detail.name === {{ Js::from($name) }}) $el.showModal()"
    x-on:close-dialog.window="if ($event.detail.name === {{ Js::from($name) }}) $el.close()"
    aria-labelledby="dialog-{{ $name }}-title"
    @if ($description) aria-describedby="dialog-{{ $name }}-description" @endif
    {{ $attributes->class('m-auto w-[calc(100%-2.5rem)] max-w-md rounded-card border-3 border-navy bg-white p-0 text-navy shadow-sticker-lg backdrop:bg-navy/50') }}
>
    <div class="flex flex-col gap-5 p-6">
        <div class="flex flex-col gap-1.5">
            <h2 id="dialog-{{ $name }}-title" class="font-display text-2xl font-extrabold leading-tight">{{ $title }}</h2>
            @if ($description)
                <p id="dialog-{{ $name }}-description" class="text-[15px] leading-relaxed text-ink-muted">{{ $description }}</p>
            @endif
        </div>

        {{ $slot }}
    </div>
</dialog>
