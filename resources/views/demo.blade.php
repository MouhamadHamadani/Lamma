{{-- /demo: the no-signup demo. Opened on a laptop or TV (the big screen); the person scans the code with their phone and plays 4 questions against two bots.
     On a phone-sized screen the page still works but says to open it on a bigger one. Same look as the landing page's "How it works". --}}
@php
    $steps = [
        ['bg-coral', 'monitor', __('Start the demo on the big screen'), __('A room opens with a code and a QR code.')],
        ['bg-teal', 'phone', __('Scan the code with your phone'), __('Pick your language, choose a nickname and tap Ready.')],
        ['bg-sun', 'trophy', __('Play 4 quick questions against two bots'), __('Same game, same screens, same sounds. It takes about two minutes.')],
    ];
@endphp
<x-layouts::lamma :title="__('Try a demo')">
    <div class="mx-auto flex min-h-dvh w-full max-w-[1200px] flex-col px-5 pb-10 lg:px-10">
        <header class="flex h-[72px] shrink-0 items-center justify-between lg:h-22">
            <a href="{{ route('home') }}"><x-lamma.logo /></a>
            <x-lamma.language-switcher />
        </header>

        <main class="relative flex grow flex-col justify-center gap-10 py-6 lg:flex-row lg:items-center lg:gap-16">
            <x-lamma.confetti :count="4" class="hidden lg:block" />

            <section class="relative flex flex-col gap-5 lg:w-1/2">
                <span class="text-xs font-bold uppercase tracking-[1.5px] text-coral-700 lg:text-sm">{{ __('Try a demo') }}</span>
                <h1 class="font-display text-[42px] font-extrabold leading-[1.04] tracking-[-1px] lg:text-[60px] lg:tracking-[-1.5px]">{{ __('Feel the game in two minutes.') }}</h1>
                <p class="max-w-[520px] text-[17px] leading-relaxed text-ink-muted lg:text-xl" data-test="demo-intro">{{ __('No account needed. Open this on a laptop or TV, then scan the code with your phone.') }}</p>

                {{-- Under 768px: the demo is a big-screen game. --}}
                <p class="flex items-start gap-3 rounded-input border-2 border-line bg-tint-sun px-4 py-3 text-[15px] font-semibold leading-relaxed md:hidden" data-test="small-screen-note">
                    <x-lamma.icon name="monitor" :size="22" class="mt-0.5 shrink-0" />
                    {{ __("You're on a small screen. The demo works best on a laptop or TV: open this page there, then scan the code with your phone.") }}
                </p>

                <form method="post" action="{{ route('demo.start') }}" class="flex flex-col gap-3">
                    @csrf
                    <div>
                        <x-lamma.button type="submit" size="lg" icon="play" class="w-full sm:w-auto" data-test="start-demo">{{ __('Start a demo game') }}</x-lamma.button>
                    </div>
                    @error('demo')
                        <x-lamma.error data-test="demo-error">{{ $message }}</x-lamma.error>
                    @enderror
                </form>

                <p class="text-[15px] text-ink-muted">
                    {{ __('Want your own games?') }}
                    <a href="{{ route('register') }}" class="font-semibold text-coral-700 underline underline-offset-4" data-test="demo-sign-up">{{ __('Sign up') }}</a>
                    <span aria-hidden="true">·</span>
                    <a href="{{ route('join') }}" class="font-semibold text-coral-700 underline underline-offset-4" data-test="demo-join">{{ __('Join a game') }}</a>
                </p>
            </section>

            <ol class="relative flex flex-col gap-3.5 lg:w-1/2" aria-label="{{ __('How the demo works') }}">
                @foreach ($steps as $n => [$bg, $icon, $title, $text])
                    <li class="flex items-center gap-4 rounded-[24px] border border-line bg-white p-5 lg:p-6">
                        <span class="{{ $bg }} flex size-12 shrink-0 items-center justify-center rounded-[16px] font-display text-2xl font-extrabold lg:size-14" dir="ltr">{{ $n + 1 }}</span>
                        <div class="flex min-w-0 grow flex-col gap-1">
                            <h2 class="font-display text-xl font-bold leading-tight lg:text-2xl">{{ $title }}</h2>
                            <p class="text-[15px] leading-relaxed text-ink-muted">{{ $text }}</p>
                        </div>
                        <x-lamma.icon :name="$icon" :size="30" class="hidden shrink-0 text-ink-muted sm:block" />
                    </li>
                @endforeach
            </ol>
        </main>
    </div>

    <x-lamma.consent-banner floating />
</x-layouts::lamma>
