{{-- Auth shell (docs/design/screens/host-1-login.html): navy brand panel beside the form. Below lg the panel is dropped and the logo moves above the form. --}}
@props(['title' => null])
@php
    $other = collect(array_keys(config('locales.supported')))->first(fn ($code) => $code !== app()->getLocale());
    $dir = fn (string $code) => config("locales.supported.{$code}.dir");
    $tagline = 'The quiz night for everyone';
@endphp
<x-layouts::lamma :title="$title">
    <div class="flex min-h-dvh flex-col lg:flex-row">
        <aside class="relative hidden shrink-0 flex-col justify-between overflow-hidden bg-navy px-[clamp(32px,4.4vw,64px)] py-14 text-cream lg:flex lg:w-[42%] lg:max-w-[600px]">
            <x-lamma.confetti :count="4" />

            <a href="{{ route('home') }}" class="relative self-start"><x-lamma.logo size="lg" on-dark /></a>

            <div class="relative flex flex-col gap-4">
                <h1 class="font-display text-[clamp(40px,4.2vw,60px)] font-extrabold leading-[1.05]">{{ __($tagline) }}.</h1>
                <p><span lang="{{ $other }}" dir="{{ $dir($other) }}" class="inline-block font-display text-[clamp(24px,2.1vw,30px)] font-bold text-coral">{{ __($tagline, [], $other) }}</span></p>
                <p class="max-w-[420px] text-lg leading-relaxed text-ink-on-dark">{{ __('Host a room on the big screen. Friends play from their phones, in Arabic or English.') }}</p>
            </div>

            <div class="relative grid w-full max-w-[380px] grid-cols-2 gap-3.5" aria-hidden="true">
                @foreach (['Venus' => '-rotate-2', 'Mars' => 'rotate-2', 'Jupiter' => 'rotate-1', 'Saturn' => '-rotate-1'] as $planet => $tilt)
                    <div @class([
                        'flex h-18 items-center gap-3 rounded-btn border-3 border-navy px-[18px] text-lg font-bold shadow-sticker-dark',
                        ['bg-coral text-navy', 'bg-teal text-navy', 'bg-sun text-navy', 'bg-navy text-cream'][$loop->index],
                        $tilt,
                    ])>
                        <x-lamma.shape :index="$loop->index" :size="18" />
                        {{ __($planet) }}
                    </div>
                @endforeach
            </div>
        </aside>

        <main class="relative flex grow items-center justify-center px-5 pb-12 pt-24 lg:py-12">
            <x-lamma.language-switcher class="absolute end-5 top-5 lg:end-12 lg:top-9" />

            <div class="flex w-full max-w-[440px] flex-col gap-5">
                <a href="{{ route('home') }}" class="self-start lg:hidden"><x-lamma.logo /></a>
                {{ $slot }}
            </div>
        </main>
    </div>

    {{-- Flux's JS, for the two-factor code input only. --}}
    @push('scripts')
        @fluxScripts
    @endpush
</x-layouts::lamma>
