{{-- The selfie step, on the join page and in the phone lobby. LIVE CAMERA ONLY: the picture comes from getUserMedia (resources/js/selfie.js), so there is no
     file input and no way to pick an existing photo. Alpine owns the whole block (wire:ignore) and reads the Livewire component through $wire: `photoUrl`
     (what to draw), `photoError` (why the last photo failed), `nickname` (the initial letter); it uploads into `photo` and calls `removePhoto()`.
     preview: draw the big avatar (the join page); the lobby already has one above the block. Steps: idle (Take a selfie / Skip, or Retake / Remove),
     camera, review (Use this photo / Retake), uploading, an error (Try again / Skip), skipped. The consent line is always there. --}}
@props(['preview' => true, 'size' => 144])
@php
    $messages = ['added' => __('Photo added'), 'removed' => __('Photo removed'), 'failed' => __('Upload failed, try again')];
    $link = 'min-h-11 px-3 text-[15px] font-semibold text-ink-muted underline underline-offset-4 hover:text-navy';
    $disc = 'relative flex aspect-square w-full max-w-72 items-center justify-center overflow-hidden rounded-full border-3 border-navy bg-navy shadow-sticker';
@endphp
<div
    wire:ignore x-data="lammaSelfie({{ Js::from($messages) }})" x-on:photo-status.window="say($event.detail.kind)" x-bind:data-step="step"
    {{ $attributes->class('flex w-full flex-col items-center gap-3') }} data-test="selfie"
>
    @if ($preview)
        <span
            x-show="step === 'idle' || step === 'skipped' || step === 'denied' || step === 'error'" data-test="selfie-preview"
            class="relative flex shrink-0 items-center justify-center overflow-hidden rounded-full border-3 border-navy bg-tint-coral font-display font-extrabold text-navy shadow-sticker"
            style="width: {{ $size }}px; height: {{ $size }}px; font-size: {{ round($size * .42) }}px"
        >
            <span aria-hidden="true" x-text="initial">?</span>
            <img x-show="photoUrl && photoUrl !== broken" x-bind:src="photoUrl" x-bind:alt="nickname" decoding="async" draggable="false" x-on:error="broken = photoUrl" class="absolute inset-0 size-full rounded-full object-cover" x-cloak>
        </span>
    @endif

    {{-- Start: no photo yet. --}}
    <div x-show="step === 'idle' && ! photoUrl && ! serverError" class="flex w-full flex-col items-center gap-1" x-cloak>
        <x-lamma.button size="lg" icon="camera" class="w-full" x-on:click="open()" x-ref="take" data-test="selfie-take">{{ __('Take a selfie') }}</x-lamma.button>
        <button type="button" x-on:click="skip()" class="{{ $link }}" data-test="selfie-skip">{{ __('Skip') }}</button>
    </div>

    {{-- A photo is set. --}}
    <div x-show="step === 'idle' && photoUrl && ! serverError" class="flex w-full flex-col items-center gap-1" x-cloak>
        <x-lamma.button variant="outline" icon="camera" class="w-full" x-on:click="open()" data-test="selfie-retake">{{ __('Retake') }}</x-lamma.button>
        <button type="button" x-on:click="remove()" class="{{ $link }}" data-test="selfie-remove">{{ __('Remove') }}</button>
    </div>

    <div x-show="step === 'skipped'" class="flex w-full justify-center" x-cloak>
        <button type="button" x-on:click="open()" class="{{ $link }}" data-test="selfie-take-later">{{ __('Take a selfie') }}</button>
    </div>

    {{-- The camera: a live, mirrored preview (the saved picture is not mirrored), the shutter, and Cancel. --}}
    <div x-show="step === 'camera'" role="group" aria-label="{{ __('Camera') }}" class="flex w-full flex-col items-center gap-4" data-test="selfie-camera" x-cloak>
        <div class="{{ $disc }}">
            <video x-ref="video" playsinline muted autoplay class="size-full -scale-x-100 object-cover" aria-hidden="true"></video>
        </div>
        <div class="grid w-full grid-cols-3 items-center">
            <button type="button" x-on:click="cancel()" class="{{ $link }} justify-self-start" data-test="selfie-cancel">{{ __('Cancel') }}</button>
            <button
                type="button" x-ref="shutter" x-on:click="shutter()" x-bind:disabled="! ready" aria-label="{{ __('Take photo') }}" data-test="selfie-shutter"
                class="sticker-press mx-auto flex size-[76px] items-center justify-center rounded-full border-3 border-navy bg-coral text-navy shadow-sticker disabled:cursor-not-allowed disabled:border-dashed disabled:border-line-strong disabled:bg-cream disabled:text-ink-subtle disabled:shadow-none"
            ><x-lamma.icon name="camera" :size="32" :stroke="2.2" /></button>
        </div>
    </div>

    {{-- The picture that was taken, until it is used. --}}
    <div x-show="step === 'review' || step === 'uploading'" class="flex w-full flex-col items-center gap-3" data-test="selfie-review" x-cloak>
        <img x-bind:src="previewUrl" alt="{{ __('Your photo') }}" class="{{ $disc }} object-cover" draggable="false">
        <div x-show="step === 'uploading'" class="flex w-full flex-col items-center gap-2" aria-busy="true">
            <div class="h-2.5 w-full max-w-72 rounded-full bg-line" role="progressbar" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="progress" aria-label="{{ __('Uploading…') }}">
                <div class="h-2.5 rounded-full bg-sun" x-bind:style="{ width: `${progress}%` }"></div>
            </div>
            <span class="text-sm font-semibold text-ink-muted">{{ __('Uploading…') }}</span>
        </div>
        <div x-show="step === 'review'" class="flex w-full flex-col items-center gap-1">
            <x-lamma.button size="lg" icon="check" class="w-full" x-on:click="use()" x-ref="use" data-test="selfie-use">{{ __('Use this photo') }}</x-lamma.button>
            <button type="button" x-on:click="retake()" class="{{ $link }}" data-test="selfie-retake-shot">{{ __('Retake') }}</button>
        </div>
    </div>

    {{-- The camera could not be opened (no camera, a blocked permission, an in-app browser): say so, offer Try again and Skip. Never a file upload. --}}
    <div x-show="step === 'denied'" class="flex w-full flex-col items-center gap-1" data-test="selfie-denied" x-cloak>
        <x-lamma.error class="w-full justify-center text-center">{{ __("We couldn't open the camera. You can allow it in your browser settings, or skip.") }}</x-lamma.error>
        <div class="flex items-center gap-2">
            <button type="button" x-on:click="open()" class="{{ $link }}" data-test="selfie-try-again">{{ __('Try again') }}</button>
            <button type="button" x-on:click="skip()" class="{{ $link }}" data-test="selfie-skip-denied">{{ __('Skip') }}</button>
        </div>
    </div>

    {{-- The upload failed, or the server did not accept the photo. --}}
    <div x-show="step === 'error' || (step === 'idle' && serverError)" class="flex w-full flex-col items-center gap-1" data-test="selfie-error" x-cloak>
        <x-lamma.error class="w-full justify-center text-center"><span x-text="serverError || {{ Js::from($messages['failed']) }}"></span></x-lamma.error>
        <div class="flex items-center gap-2">
            <button type="button" x-on:click="open()" class="{{ $link }}" data-test="selfie-retry">{{ __('Try again') }}</button>
            <button type="button" x-on:click="skip()" class="{{ $link }}" data-test="selfie-skip-error">{{ __('Skip') }}</button>
        </div>
    </div>

    <p class="text-center text-sm text-ink-muted" data-test="selfie-consent">{{ __('Your photo is shown on the host screen and deleted after the game.') }}</p>

    <p class="sr-only" role="status" aria-live="polite" x-text="status" data-test="selfie-status"></p>
</div>
