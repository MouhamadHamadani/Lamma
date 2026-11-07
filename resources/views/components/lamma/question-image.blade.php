{{-- The picture of a picture question, above the options: a navy-outlined frame with the picture fitted inside it (never cropped, never stretched). The size comes
     from the caller (host: a height in dvh, the width follows; phone: full width, a short height), so the picture shrinks before anything else on the screen does.
     The alt text is deliberately the same for every question: it must never describe the picture, so it can never give the answer away. A picture that fails to load
     takes its frame with it. --}}
@props(['src'])
<figure {{ $attributes->class('relative flex items-center justify-center overflow-hidden rounded-card border-3 border-navy bg-white') }} data-test="question-image">
    <img src="{{ $src }}" alt="{{ __('Picture for this question') }}" decoding="async" draggable="false" onerror="this.parentElement.remove()" class="size-full object-contain">
</figure>
