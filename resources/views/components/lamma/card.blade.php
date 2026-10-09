{{-- White card for a form or a settings section. sticker (default): navy outline + hard shadow. plain: 2px line border, no shadow.
     title / description add a heading block; the slot is the body. --}}
@props(['title' => null, 'description' => null, 'sticker' => true])
<section {{ $attributes->class([
    'flex flex-col gap-5 rounded-card bg-white p-5 sm:p-6',
    'border-3 border-navy shadow-sticker' => $sticker,
    'border-2 border-line' => ! $sticker,
]) }}>
    @if ($title)
        <header class="flex flex-col gap-1">
            <h2 class="font-display text-2xl font-extrabold leading-tight">{{ $title }}</h2>
            @if ($description)
                <p class="text-[15px] leading-relaxed text-ink-muted">{{ $description }}</p>
            @endif
        </header>
    @endif

    {{ $slot }}
</section>
