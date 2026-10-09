{{-- Public landing page. Reference: docs/design/screens/landing-desktop.html (>= 1024px) and landing-phone-*.html (below). --}}
@php
    $hostUrl = auth()->check() ? route('rooms.create') : route('login');
    $joinUrl = route('join');
    $nav = [['#how', __('How it works')], ['#hosts', __('For hosts')]];
    if ($categories->isNotEmpty()) {
        array_splice($nav, 1, 0, [['#categories', __('Categories')]]);
    }
    // The bilingual demo shows both languages whatever the page language is.
    $t = fn (string $key, string $locale) => __($key, [], $locale);
    $faces = ['bg-coral text-navy', 'bg-teal text-navy', 'bg-sun text-navy', 'bg-navy text-cream'];
    $planets = ['Venus', 'Mars', 'Jupiter', 'Saturn'];
    $eyebrow = 'text-xs font-bold uppercase tracking-[1.5px] text-coral-700 lg:text-sm';
    $h2 = 'font-display text-[34px] font-extrabold leading-[1.1] tracking-[-.5px] lg:text-5xl lg:tracking-[-1px]';
    $card = 'border border-line bg-white';
    // Marker stripe as a thick underline pulled up into the glyphs: unlike an inset shadow it doesn't depend on the font's line box.
    $hl = 'underline decoration-sun decoration-[.3em] underline-offset-[-.12em] [text-decoration-skip-ink:none]';
    $gutter = 'mx-auto w-full max-w-[1440px] px-5 lg:px-[clamp(40px,8.33vw,120px)]';
    $floatShadow = 'shadow-[0_18px_40px_color-mix(in_srgb,var(--color-navy)_18%,transparent)]';
@endphp
<x-layouts::lamma :title="__('The quiz night for everyone')">
    {{-- HEADER --}}
    <header x-data="{ open: false }" class="relative mx-auto flex h-[72px] max-w-[1440px] items-center justify-between px-5 lg:h-22 lg:px-[clamp(40px,8.33vw,120px)]">
        <a href="{{ route('home') }}"><x-lamma.logo /></a>

        <nav class="hidden gap-9 font-medium text-ink-muted lg:flex">
            @foreach ($nav as [$href, $label])
                <a href="{{ $href }}" class="hover:text-coral-700">{{ $label }}</a>
            @endforeach
        </nav>

        <div class="flex items-center gap-2 lg:gap-5">
            <x-lamma.language-switcher />
            @auth
                <x-lamma.user-menu />
            @else
                <a href="{{ route('login') }}" class="hidden font-semibold hover:text-coral-700 lg:block">{{ __('Log in') }}</a>
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="hidden h-12 items-center rounded-input border-2 border-navy px-[22px] font-semibold hover:bg-tint-navy lg:flex">{{ __('Sign up') }}</a>
                @endif
            @endauth

            <button
                type="button" aria-controls="mobile-menu" x-on:click="open = ! open" x-bind:aria-expanded="open"
                x-bind:aria-label="open ? {{ Js::from(__('Close menu')) }} : {{ Js::from(__('Open menu')) }}"
                aria-label="{{ __('Open menu') }}"
                class="flex size-11 items-center justify-center rounded-input bg-navy text-cream shadow-sticker-coral-sm lg:hidden"
            >
                <x-lamma.icon name="menu" :size="20" :stroke="2.2" x-show="! open" />
                <x-lamma.icon name="x" :size="20" :stroke="2.2" x-show="open" x-cloak />
            </button>
        </div>

        <div id="mobile-menu" x-show="open" x-cloak x-on:click.outside="open = false" class="absolute inset-x-5 top-full z-20 flex flex-col rounded-card border-2 border-line bg-white p-3 shadow-soft lg:hidden">
            @foreach ($nav as [$href, $label])
                <a href="{{ $href }}" x-on:click="open = false" class="flex h-12 items-center rounded-input px-3 font-semibold hover:bg-tint-navy">{{ $label }}</a>
            @endforeach
            @auth
                <a href="{{ route('me.games') }}" class="flex h-12 items-center rounded-input px-3 font-semibold hover:bg-tint-navy">{{ __('My games') }}</a>
            @else
                <a href="{{ route('login') }}" class="flex h-12 items-center rounded-input px-3 font-semibold hover:bg-tint-navy">{{ __('Log in') }}</a>
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="flex h-12 items-center rounded-input px-3 font-semibold hover:bg-tint-navy">{{ __('Sign up') }}</a>
                @endif
            @endauth
        </div>
    </header>

    <main>
        @if (session('notice'))
            <div class="{{ $gutter }} pt-2"><x-lamma.notice /></div>
        @endif

        {{-- HERO --}}
        <section class="relative mx-auto flex max-w-[1440px] flex-col gap-10 px-5 pb-6 pt-5 lg:px-[clamp(40px,8.33vw,120px)] lg:pb-20 lg:pt-10 xl:flex-row xl:items-center xl:gap-[60px]">
            <x-lamma.confetti :count="6" class="hidden xl:block" />
            <x-lamma.shape :index="0" :size="20" class="absolute end-[26px] top-[30px] rotate-[14deg] text-coral xl:hidden rtl:-rotate-[14deg]" />
            <x-lamma.shape :index="1" :size="14" class="absolute end-[70px] top-[160px] text-teal xl:hidden" />

            <div class="relative flex flex-col gap-5 lg:gap-7 xl:w-[clamp(440px,37.5vw,540px)] xl:shrink-0">
                <x-lamma.chip size="sm" class="self-start text-ink-muted lg:h-9 lg:text-sm">
                    <span class="flex gap-1" aria-hidden="true">
                        <span class="size-[7px] rounded-full bg-coral lg:size-2"></span>
                        <span class="size-[7px] rounded-full bg-teal lg:size-2"></span>
                        <span class="size-[7px] rounded-full bg-sun lg:size-2"></span>
                        <span class="size-[7px] rounded-full bg-teal lg:size-2"></span>
                    </span>
                    {{ __('The quiz night for everyone') }}
                </x-lamma.chip>

                <h1 class="font-display text-[46px] font-extrabold leading-[1.04] tracking-[-1px] md:text-[56px] lg:text-[68px] lg:leading-[1.02] lg:tracking-[-1.5px]">
                    {{ __('Turn any screen into a') }} <span class="{{ $hl }}">{{ __('quiz show.') }}</span>
                </h1>

                <p class="max-w-[500px] text-[17px] leading-relaxed text-ink-muted lg:text-xl">
                    {{ __('One screen shows the questions. Everyone answers from their own phone, in Arabic or English. Most points wins.') }}
                </p>

                {{-- Phone order: join box, "or", host. Desktop order: host, then join box. --}}
                <form action="{{ $joinUrl }}" method="get" class="order-1 flex max-w-[480px] flex-col gap-3 rounded-card border border-line bg-white p-[18px] shadow-soft lg:order-3 lg:p-5">
                    <label for="join-code" class="text-[15px] font-semibold">{{ __('Got a room code?') }}</label>
                    <div class="flex flex-col gap-2.5 lg:flex-row">
                        <input
                            id="join-code" name="code" type="text" dir="ltr" maxlength="6" autocomplete="off" autocapitalize="characters" spellcheck="false"
                            placeholder="{{ __('e.g. K7MP') }}"
                            class="h-[60px] min-w-0 grow rounded-input border-2 border-line bg-cream px-4 text-center font-display text-2xl font-bold uppercase tracking-[8px] placeholder:text-ink-subtle placeholder:normal-case placeholder:tracking-normal lg:h-14 lg:text-start lg:text-[22px] lg:tracking-[6px]"
                        >
                        <x-lamma.button type="submit" variant="dark">{{ __('Join game') }}</x-lamma.button>
                    </div>
                    <p class="hidden text-[13px] text-ink-subtle lg:block">{{ __("No app needed. It runs in your phone's browser.") }}</p>
                </form>

                <div class="order-2 flex items-center gap-3 text-[13px] font-semibold text-ink-subtle lg:hidden" aria-hidden="true">
                    <span class="h-px grow bg-line"></span>{{ __('or') }}<span class="h-px grow bg-line"></span>
                </div>

                <div class="order-4 flex items-center gap-5 lg:order-2">
                    <x-lamma.button :href="$hostUrl" icon="play" class="w-full lg:w-auto lg:min-h-[60px] lg:text-lg">{{ __('Host a game') }}</x-lamma.button>
                    <a href="#how" class="hidden items-center gap-2 text-[17px] font-semibold hover:text-coral-700 lg:flex">
                        {{ __('See how it works') }}
                        <x-lamma.icon name="arrow-right" :size="18" :stroke="2.2" class="rtl:-scale-x-100" />
                    </a>
                </div>
            </div>

            {{-- Illustration: a TV with a question, plus (desktop only) two phones, one per language. --}}
            <div class="relative mx-auto w-full max-w-[540px] md:h-[560px] md:w-[600px] md:max-w-none xl:mx-0 xl:[zoom:.8] min-[1440px]:[zoom:1]" aria-hidden="true">
                <div class="md:absolute md:start-0 md:top-[30px] md:w-[540px]">
                    <div class="rounded-[22px] bg-navy p-[9px] md:h-[350px] md:rounded-[26px] md:p-3 {{ $floatShadow }}">
                        <div class="flex h-full flex-col gap-[11px] rounded-[14px] bg-cream p-3.5 md:gap-4 md:rounded-2xl md:p-5">
                            <div class="flex items-center justify-between">
                                <div class="flex gap-2">
                                    <x-lamma.chip tone="teal" size="sm">{{ __('Science') }}</x-lamma.chip>
                                    <x-lamma.chip size="sm">{{ __('Question 3 / 10') }}</x-lamma.chip>
                                </div>
                                <div dir="ltr" class="flex size-9 items-center justify-center rounded-full border-[3px] border-sun bg-white font-display text-[15px] font-extrabold md:size-[46px] md:border-4 md:text-lg">12</div>
                            </div>
                            <p class="font-display text-[19px] font-bold leading-tight md:text-[26px]">{{ __('Which planet is known as the Red Planet?') }}</p>
                            <div class="grid grid-cols-2 gap-[7px] md:gap-2.5">
                                @foreach ($planets as $i => $planet)
                                    <div class="{{ $faces[$i] }} flex h-[38px] items-center gap-2 rounded-[10px] px-2.5 text-[13px] font-semibold md:h-[50px] md:gap-2.5 md:rounded-xl md:px-3.5 md:text-[15px]">
                                        <x-lamma.shape :index="$i" :size="14" />{{ __($planet) }}
                                    </div>
                                @endforeach
                            </div>
                            <div class="hidden items-center gap-2.5 text-[13px] font-semibold text-ink-muted md:flex">
                                <span class="flex">
                                    <span class="flex size-6 items-center justify-center rounded-full border-2 border-cream bg-tint-coral text-[11px] text-navy">S</span>
                                    <span class="-ms-1.5 flex size-6 items-center justify-center rounded-full border-2 border-cream bg-teal text-[11px] text-navy">A</span>
                                </span>
                                {{ __('2 of 3 answered') }}
                            </div>
                        </div>
                    </div>
                    <div class="mx-auto h-3.5 w-20 rounded-b-lg bg-navy-700 md:h-[26px]"></div>
                    <div class="mx-auto h-2 w-[130px] rounded-full bg-navy-700 md:h-2.5 md:w-40"></div>
                </div>

                <span dir="ltr" class="absolute -top-3 start-3 flex h-[34px] -rotate-4 items-center gap-1.5 rounded-xl border border-line bg-white px-3 text-xs font-semibold shadow-soft rtl:rotate-4 md:-start-6 md:top-0 md:h-10 md:gap-2 md:px-4 md:text-[13px]">
                    {{ __('Room') }}
                    <span class="font-display text-[15px] font-extrabold tracking-[2px] md:text-[17px] md:tracking-[3px]">K7MP</span>
                </span>

                @foreach ([['en', 'start-[330px] top-[250px] -rotate-5 rtl:rotate-5', 'Sara · EN'], ['ar', 'start-[460px] top-[200px] rotate-6 rtl:-rotate-6', 'علي · ع']] as [$loc, $pos, $who])
                    <div @if ($loc === 'ar') dir="rtl" lang="ar" @endif class="{{ $pos }} absolute hidden h-[300px] w-[150px] rounded-[28px] bg-navy p-2 md:block {{ $floatShadow }}">
                        <div class="flex h-full flex-col gap-[7px] rounded-[21px] bg-white px-2.5 pb-2.5 pt-4">
                            <div class="flex justify-between px-0.5 pb-1 text-[10px] font-bold text-ink-muted"><span>{{ $who }}</span><span dir="ltr">0:12</span></div>
                            @foreach ($planets as $i => $planet)
                                <div @class([$faces[$i], 'flex h-11 items-center gap-[7px] rounded-[10px] px-2.5 text-xs', 'font-bold shadow-[0_0_0_3px_var(--color-navy)]' => $loc === 'en' && $i === 1, 'font-semibold' => ! ($loc === 'en' && $i === 1)])>
                                    <x-lamma.shape :index="$i" :size="12" />{{ $t($planet, $loc) }}
                                </div>
                            @endforeach
                            <div @class(['mt-auto text-center text-[10px] font-semibold', 'text-teal-800' => $loc === 'en', 'text-ink-muted' => $loc === 'ar'])>
                                {{ $t($loc === 'en' ? 'Answer locked in' : 'Pick your answer', $loc) }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- BILINGUAL --}}
        <section id="hosts" class="mx-auto max-w-[1440px] px-3 pb-6 pt-4 lg:px-[clamp(40px,8.33vw,120px)] lg:pb-[60px] lg:pt-5">
            <div class="flex flex-col gap-3.5 rounded-[32px] bg-tint-coral px-[22px] py-[30px] lg:rounded-[40px] lg:px-[72px] lg:py-16 xl:flex-row xl:items-center xl:gap-16">
                <div class="flex flex-col gap-3.5 lg:gap-5 xl:w-[440px] xl:shrink-0">
                    <span class="{{ $eyebrow }}">{{ __('Bilingual by design') }}</span>
                    <h2 class="font-display text-[32px] font-extrabold leading-[1.1] tracking-[-.5px] lg:text-5xl lg:leading-[1.08] lg:tracking-[-1px]">{{ __('Arabic and English, in the same room.') }}</h2>
                    <p class="text-base leading-relaxed text-ink-muted lg:text-lg">{{ __('Each player picks their language when they join. The host screen can show Arabic, English, or both.') }}</p>
                    <div class="hidden flex-wrap gap-2.5 pt-1.5 lg:flex">
                        @foreach ([['S', 'bg-tint-coral', 'Sara · EN'], ['A', 'bg-teal', 'Ali · عربي'], ['M', 'bg-sun', 'Maya · EN']] as [$initial, $bg, $label])
                            <span class="flex h-10 items-center gap-2.5 rounded-full bg-white pe-4 ps-1.5 text-sm font-semibold">
                                <span class="{{ $bg }} flex size-7 items-center justify-center rounded-full text-xs">{{ $initial }}</span>{{ $label }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <div class="flex grow flex-col gap-3.5 lg:gap-[18px]">
                    @foreach (['en', 'ar'] as $loc)
                        <div @if ($loc === 'ar') dir="rtl" lang="ar" @endif class="flex flex-col gap-3.5 rounded-[18px] bg-white p-4 shadow-soft lg:rounded-[24px] lg:px-7 lg:py-[26px]">
                            <div class="flex justify-between text-[13px] font-bold text-ink-muted"><span>{{ config("locales.supported.$loc.short") }}</span><span>{{ $t('Question 3', $loc) }}</span></div>
                            <p class="font-display text-lg font-bold lg:text-[26px]">{{ $t('Which planet is known as the Red Planet?', $loc) }}</p>
                            <div class="flex gap-1.5 lg:gap-2">
                                @foreach ($planets as $i => $planet)
                                    <span class="{{ $faces[$i] }} flex h-9 min-w-0 grow items-center justify-center truncate rounded-[10px] px-1 text-[13px] font-semibold lg:h-[38px] lg:text-sm">{{ $t($planet, $loc) }}</span>
                                @endforeach
                            </div>
                        </div>
                        @if ($loop->first)
                            <div class="flex h-8 items-center gap-2 self-center rounded-full bg-navy px-3.5 text-[13px] font-semibold text-cream">
                                <x-lamma.icon name="arrows-v" :size="14" :stroke="2.4" />{{ __('Same question, same moment') }}
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </section>

        {{-- HOW IT WORKS --}}
        <section id="how" class="{{ $gutter }} flex scroll-mt-4 flex-col gap-7 py-10 lg:items-center lg:gap-12 lg:py-[60px]">
            <div class="flex flex-col gap-2 lg:items-center lg:gap-3 lg:text-center">
                <span class="{{ $eyebrow }}">{{ __('How it works') }}</span>
                <h2 class="{{ $h2 }}">{{ __('Game night in three steps') }}</h2>
            </div>
            <div class="grid w-full gap-3.5 lg:grid-cols-3 lg:gap-7">
                @foreach ([
                    ['bg-coral', 'monitor', 'Create a room', 'Pick categories, number of questions, timer and difficulty. You get a short room code.'],
                    ['bg-teal', 'phone', 'Friends join', 'They open the site on their phones, type the code, choose a language and tap Ready.'],
                    ['bg-sun', 'trophy', 'Play and climb', 'Answer before the timer runs out. Points after every question, and a podium at the end.'],
                ] as $n => [$bg, $icon, $title, $text])
                    <div class="{{ $card }} grid grid-cols-[auto_1fr] items-center gap-x-3.5 gap-y-2.5 p-[22px] lg:grid-cols-[1fr_auto] lg:gap-y-4 lg:p-8 rounded-[24px] lg:rounded-card">
                        <span class="{{ $bg }} flex size-[46px] items-center justify-center rounded-[15px] font-display text-2xl font-extrabold lg:size-14 lg:rounded-[18px] lg:text-[28px]">{{ $n + 1 }}</span>
                        <x-lamma.icon :name="$icon" :size="30" class="hidden text-ink-muted lg:col-start-2 lg:row-start-1 lg:block" />
                        <h3 class="font-display text-[22px] font-bold lg:col-span-2 lg:row-start-2 lg:text-[26px]">{{ __($title) }}</h3>
                        <p class="col-span-2 text-[15px] leading-relaxed text-ink-muted lg:text-base">{{ __($text) }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- CATEGORIES (real ones from the database) --}}
        @if ($categories->isNotEmpty())
            <section id="categories" class="{{ $gutter }} flex scroll-mt-4 flex-col gap-[22px] py-10 lg:gap-11 lg:py-[60px]">
                <div class="flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
                    <div class="flex flex-col gap-2 lg:gap-3">
                        <span class="{{ $eyebrow }}">{{ __('Categories') }}</span>
                        <h2 class="{{ $h2 }}">{{ __('Pick what you love') }}</h2>
                    </div>
                    <p class="max-w-[380px] text-[15px] leading-relaxed text-ink-muted lg:text-lg">{{ __('Play one category or mix several in one game.') }}</p>
                </div>
                <ul @class([
                    'grid grid-cols-2 gap-3 lg:grid-cols-3 lg:gap-5',
                    [3 => 'xl:grid-cols-3', 4 => 'xl:grid-cols-4', 5 => 'xl:grid-cols-5', 6 => 'xl:grid-cols-6'][max(3, $categories->count())],
                ])>
                    @foreach ($categories as $category)
                        <li class="{{ \App\Support\CategoryStyle::tint($category->slug) }} flex h-24 flex-col justify-between rounded-[22px] px-4 py-3.5 lg:h-[220px] lg:rounded-card lg:p-6">
                            <span class="flex items-center lg:size-[60px] lg:justify-center lg:rounded-[18px] lg:bg-white">
                                <x-lamma.icon :name="\App\Support\CategoryStyle::icon($category->slug)" :size="30" class="size-[26px] lg:size-[30px]" />
                            </span>
                            <span class="font-display text-lg font-bold lg:text-[22px]">{{ $category->name }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- FEATURES --}}
        <section class="{{ $gutter }} flex flex-col gap-[22px] pb-10 pt-[30px] lg:gap-10 lg:pb-[60px] lg:pt-10">
            <h2 class="{{ $h2 }} lg:text-center">{{ __('Made for the living room') }}</h2>
            <div class="grid gap-3 md:grid-cols-2 lg:gap-6 xl:grid-cols-4">
                @foreach ([
                    ['bg-tint-teal', 'phone', 'No app to install', 'Works in any phone browser.'],
                    ['bg-tint-navy', 'chart', 'Live scoreboard', 'Rankings update after every question.'],
                    ['bg-tint-coral', 'sliders', 'You set the rules', 'Categories, count, timer and difficulty.'],
                    ['bg-tint-sun', 'clock', 'Fair for everyone', 'Same questions, one clock for all.'],
                ] as [$bg, $icon, $title, $text])
                    <div class="{{ $card }} flex items-center gap-3.5 rounded-[20px] p-4 lg:flex-col lg:items-start lg:rounded-[24px] lg:p-7">
                        <span class="{{ $bg }} flex size-[46px] shrink-0 items-center justify-center rounded-[14px] lg:size-[52px] lg:rounded-2xl">
                            <x-lamma.icon :name="$icon" :size="26" />
                        </span>
                        <div class="flex flex-col gap-0.5 lg:gap-3.5">
                            <h3 class="text-base font-bold lg:font-display lg:text-[22px]">{{ __($title) }}</h3>
                            <p class="text-sm leading-relaxed text-ink-muted lg:text-[15px]">{{ __($text) }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- CALL TO ACTION --}}
        <section class="mx-auto max-w-[1440px] px-3 pb-7 pt-3 lg:px-[clamp(40px,8.33vw,120px)] lg:pb-[72px] lg:pt-10">
            <div class="relative flex flex-col gap-3.5 overflow-hidden rounded-[32px] bg-navy px-6 pb-0 pt-8 lg:rounded-[40px] lg:px-20 lg:py-16 xl:flex-row xl:items-center xl:justify-between xl:gap-6">
                <x-lamma.confetti :count="4" class="hidden xl:block" />
                <x-lamma.shape :index="0" :size="20" class="absolute end-6 top-[26px] text-coral xl:hidden" />
                <div class="relative flex flex-col gap-3.5 lg:gap-[22px] xl:w-[clamp(400px,36vw,520px)]">
                    <h2 class="font-display text-[38px] font-extrabold leading-[1.05] text-cream lg:text-[56px] lg:tracking-[-1px]">{{ __('Ready for game night?') }}</h2>
                    <p class="text-base leading-relaxed text-ink-on-dark lg:text-[19px]">{{ __('Create a room, share the code, and let the questions begin.') }}</p>
                    <div class="flex flex-col gap-2.5 pt-1.5 lg:flex-row lg:gap-3.5 lg:pt-2">
                        <x-lamma.button variant="sun" :href="$hostUrl" class="lg:min-h-[60px]">{{ __('Host a game') }}</x-lamma.button>
                        <x-lamma.button variant="outline" on-dark :href="$joinUrl" class="lg:min-h-[60px]">{{ __('Join a room') }}</x-lamma.button>
                    </div>
                </div>

                {{-- Decorative podium: 2nd, 1st (crown), 3rd --}}
                <div class="relative mt-auto flex items-end justify-center gap-2 pt-6 lg:mt-8 lg:h-[340px] lg:gap-3 lg:pt-0 xl:mt-0" aria-hidden="true">
                    @foreach ([
                        ['S', 'bg-tint-coral', 'bg-teal', 2, 'size-11 text-lg lg:size-16 lg:text-[26px]', 'h-20 w-[84px] text-[28px] lg:h-[150px] lg:w-[120px] lg:text-[40px]'],
                        ['A', 'bg-teal', 'bg-sun', 1, 'size-[52px] text-[22px] lg:size-[76px] lg:text-3xl', 'h-[110px] w-[90px] text-[34px] lg:h-[200px] lg:w-[130px] lg:text-5xl'],
                        ['M', 'bg-sun', 'bg-coral', 3, 'size-11 text-lg lg:size-16 lg:text-[26px]', 'h-[60px] w-[84px] text-[26px] lg:h-[110px] lg:w-[120px] lg:text-4xl'],
                    ] as [$initial, $avatarBg, $barBg, $rank, $avatarSize, $barSize])
                        <div class="flex flex-col items-center gap-2 lg:gap-3">
                            @if ($rank === 1)
                                <svg width="36" height="28" viewBox="0 0 36 28" class="hidden fill-sun lg:block"><path d="M2 8l8 7 8-13 8 13 8-7-4 20H6z"/></svg>
                            @endif
                            <span class="{{ $avatarBg }} {{ $avatarSize }} flex items-center justify-center rounded-full border-4 border-navy font-display font-extrabold text-navy">{{ $initial }}</span>
                            <span class="{{ $barBg }} {{ $barSize }} flex justify-center rounded-t-[14px] pt-2 font-display font-extrabold text-navy lg:rounded-t-[18px] lg:pt-3.5">{{ $rank }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    </main>

    {{-- FOOTER --}}
    <footer class="mx-auto max-w-[1440px] px-5 lg:px-[clamp(40px,8.33vw,120px)]">
        <div class="flex flex-col gap-[18px] border-t border-line py-6 lg:h-[140px] lg:flex-row lg:items-center lg:justify-between lg:pb-5 lg:pt-0">
            <x-lamma.logo size="sm" />
            <nav class="flex flex-wrap gap-x-[22px] gap-y-2 font-medium text-ink-muted lg:gap-x-8">
                <a href="#how" class="hover:text-coral-700">{{ __('How it works') }}</a>
                @if ($categories->isNotEmpty())
                    <a href="#categories" class="hover:text-coral-700">{{ __('Categories') }}</a>
                @endif
                @auth
                    <a href="{{ route('me.games') }}" class="hover:text-coral-700">{{ __('My games') }}</a>
                @else
                    <a href="{{ route('login') }}" class="hover:text-coral-700">{{ __('Log in') }}</a>
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="hover:text-coral-700">{{ __('Sign up') }}</a>
                    @endif
                @endauth
            </nav>
            <span class="text-[13px] text-ink-subtle lg:text-sm">&copy; {{ now()->year }} Lamma · <span lang="ar">لمّة</span></span>
        </div>
    </footer>
</x-layouts::lamma>
