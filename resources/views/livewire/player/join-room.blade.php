{{-- Reference: docs/design/screens/player-1-join.html. Designed at 390x844; centred in a phone-width column on bigger screens. --}}
@use('App\Game\RoomCodeGenerator')
@php
    $input = 'w-full rounded-btn border-2 bg-white px-[18px] text-navy placeholder:text-ink-subtle';
@endphp
<form wire:submit="join" class="relative mx-auto flex min-h-dvh w-full max-w-md flex-col px-5 pb-7" novalidate>
    <header class="flex h-16 shrink-0 items-center justify-between">
        <a href="{{ route('home') }}"><x-lamma.logo size="sm" /></a>
        <x-lamma.language-switcher />
    </header>

    <x-lamma.notice class="relative" />

    <x-lamma.shape :index="2" :size="22" class="absolute end-7 top-[90px] rotate-[14deg] text-sun rtl:-rotate-[14deg]" />
    <x-lamma.shape :index="1" :size="12" class="absolute end-[74px] top-[134px] text-teal" />

    <div class="relative flex grow flex-col gap-5 pt-3">
        <div class="flex flex-col gap-1.5">
            <h1 class="font-display text-[40px] font-extrabold leading-[1.05]">{{ __('Join a game') }}</h1>
            <p class="text-base text-ink-muted">{{ __('Type the code shown on the big screen.') }}</p>
        </div>

        {{-- Room code: always left-to-right, upper case, and only the characters a code can contain (no look-alikes). --}}
        <div class="flex flex-col gap-2">
            <label for="join-code" class="text-[15px] font-bold">{{ __('Room code') }}</label>
            <input
                id="join-code" type="text" dir="ltr" wire:model="code" value="{{ $code }}" maxlength="6" data-test="code-input"
                autocomplete="off" autocapitalize="characters" spellcheck="false" @if ($code === '') autofocus @endif
                x-data x-on:input="$el.value = $el.value.toUpperCase().replace(/[^{{ RoomCodeGenerator::ALPHABET }}]/g, '').slice(0, 6)"
                @error('code') aria-invalid="true" aria-describedby="join-code-error" @enderror
                class="{{ $input }} h-16 text-center font-display text-3xl font-extrabold uppercase tracking-[10px] {{ $errors->has('code') ? 'border-coral-700' : 'border-line' }}"
            >
            @error('code')
                <x-lamma.error id="join-code-error" data-test="code-error">{{ $message }}</x-lamma.error>
            @enderror
        </div>

        <div class="flex flex-col gap-2">
            <label for="join-nickname" class="text-[15px] font-bold">{{ __('Your nickname') }}</label>
            <input
                id="join-nickname" type="text" dir="ltr" wire:model="nickname" value="{{ $nickname }}" maxlength="20" data-test="nickname-input"
                autocomplete="nickname" placeholder="{{ __('e.g. Sara') }}" @if ($code !== '') autofocus @endif
                @error('nickname') aria-invalid="true" aria-describedby="join-nickname-error" @enderror
                class="{{ $input }} h-14 text-lg font-semibold {{ $errors->has('nickname') ? 'border-coral-700' : 'border-line' }}"
            >
            @error('nickname')
                <x-lamma.error id="join-nickname-error" data-test="nickname-error">{{ $message }}</x-lamma.error>
            @enderror
        </div>

        <div class="flex flex-col gap-2" role="group" aria-labelledby="join-language">
            <span id="join-language" class="text-[15px] font-bold">{{ __('Play in') }}</span>
            <div class="flex gap-3">
                @foreach (['en' => 'English', 'ar' => 'العربية'] as $value => $label)
                    <button
                        type="button" lang="{{ $value }}" wire:click="$set('language', '{{ $value }}')" aria-pressed="{{ $language === $value ? 'true' : 'false' }}"
                        data-test="language-{{ $value }}"
                        @class([
                            'flex h-18 flex-1 items-center justify-center gap-2 rounded-[18px] text-lg font-bold',
                            'sticker-sm sticker-press bg-tint-sun' => $language === $value,
                            'border-2 border-line bg-white' => $language !== $value,
                            'font-display text-[22px]' => $value === 'ar',
                        ])
                    >
                        @if ($language === $value)
                            <x-lamma.icon name="check" :size="22" :stroke="3" />
                        @endif
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="grow"></div>

        <x-lamma.button type="submit" size="lg" icon="play" class="w-full" wire:loading.attr="disabled" wire:target="join" data-test="join-button">{{ __('Join game') }}</x-lamma.button>

        @guest
            <p class="text-center text-sm text-ink-subtle">
                {{ __('Have an account?') }}
                <a href="{{ route('login') }}" class="font-bold text-navy underline underline-offset-4">{{ __('Log in') }}</a>
            </p>
        @endguest
    </div>
</form>
