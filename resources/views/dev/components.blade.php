{{-- Local-only visual check of every <x-lamma.*> component, once in English (LTR) and once in Arabic (RTL). Route: /_components. --}}
<x-layouts::lamma>
    @php
        $original = app()->getLocale();
        $planets = [['Venus', 'الزهرة'], ['Mars', 'المريخ'], ['Jupiter', 'المشتري'], ['Saturn', 'زحل']];
        $endsAt = now()->addSeconds(12);
        $endsSoon = now()->addSeconds(4);
        $icons = ['check', 'x', 'lock', 'arrow-right', 'menu', 'play', 'monitor', 'phone', 'trophy', 'chart', 'sliders', 'clock', 'arrows-v', 'globe', 'flask', 'ball', 'landmark', 'film', 'utensils', 'bulb', 'sparkles'];
    @endphp

    <main class="mx-auto max-w-[1600px] space-y-20 p-8">
        <h1 class="font-display text-5xl font-extrabold">Lamma components</h1>

        @foreach (['en', 'ar'] as $loc)
            @php
                app()->setLocale($loc);
                $ar = $loc === 'ar';
                $players = [
                    ['nickname' => $ar ? 'سارة' : 'Sara', 'locale' => $loc, 'is_ready' => true],
                    ['nickname' => $ar ? 'علي' : 'Ali', 'locale' => 'ar', 'is_ready' => true],
                    ['nickname' => $ar ? 'مايا' : 'Maya', 'locale' => 'en', 'is_ready' => false],
                ];
                $categories = [
                    ['slug' => 'geography', 'name' => $ar ? 'جغرافيا' : 'Geography'],
                    ['slug' => 'science', 'name' => $ar ? 'علوم' : 'Science'],
                    ['slug' => 'sports', 'name' => $ar ? 'رياضة' : 'Sports'],
                    ['slug' => 'history', 'name' => $ar ? 'تاريخ' : 'History'],
                    ['slug' => 'movies-tv', 'name' => $ar ? 'أفلام ومسلسلات' : 'Movies & TV'],
                    ['slug' => 'food-drink', 'name' => $ar ? 'طعام وشراب' : 'Food & Drink'],
                ];
            @endphp

            <section lang="{{ $loc }}" dir="{{ $ar ? 'rtl' : 'ltr' }}" class="space-y-12 rounded-panel border-2 border-line bg-white p-8">
                <h2 class="font-display text-4xl font-extrabold">{{ $ar ? 'العربية (RTL)' : 'English (LTR)' }}</h2>

                <div class="space-y-4">
                    <h3 class="font-display text-2xl font-bold">Logo · language switcher · chips · room code</h3>
                    <div class="flex flex-wrap items-center gap-8">
                        <x-lamma.logo size="sm" />
                        <x-lamma.logo size="md" />
                        <x-lamma.logo size="lg" />
                        <span class="inline-flex rounded-card bg-navy p-5"><x-lamma.logo size="md" on-dark /></span>
                        <x-lamma.language-switcher />
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-lamma.chip>Question 3 of 10</x-lamma.chip>
                        <x-lamma.chip tone="coral" icon="flask">Science</x-lamma.chip>
                        <x-lamma.chip tone="sun" size="sm">100 pts</x-lamma.chip>
                        <x-lamma.chip tone="teal" size="lg">Geography · Science</x-lamma.chip>
                        <x-lamma.chip tone="navy">Lobby</x-lamma.chip>
                        <x-lamma.chip tone="line">+0</x-lamma.chip>
                        <x-lamma.room-code code="k7mp" size="chip">Room</x-lamma.room-code>
                    </div>
                    <x-lamma.room-code code="K7MP" />
                </div>

                <div class="space-y-4">
                    <h3 class="font-display text-2xl font-bold">Buttons</h3>
                    <div class="flex flex-wrap items-center gap-4">
                        <x-lamma.button icon="play">Create room</x-lamma.button>
                        <x-lamma.button size="lg" icon="arrow-right">Next question</x-lamma.button>
                        <x-lamma.button variant="dark">Log in</x-lamma.button>
                        <x-lamma.button variant="outline">Sign up</x-lamma.button>
                        <x-lamma.button variant="ghost">Forgot password?</x-lamma.button>
                        <x-lamma.button href="#" variant="sun">As a link</x-lamma.button>
                        <x-lamma.button size="lg" disabled>Start game</x-lamma.button>
                    </div>
                    <div class="relative overflow-hidden rounded-panel bg-navy p-8">
                        <x-lamma.confetti :count="8" />
                        <div class="relative flex flex-wrap items-center gap-4">
                            <x-lamma.button on-dark size="lg">Play again</x-lamma.button>
                            <x-lamma.button variant="outline" on-dark size="lg">New game</x-lamma.button>
                            <x-lamma.button variant="sun" size="lg">Host a game</x-lamma.button>
                            <x-lamma.button variant="ghost" on-dark>Leave room</x-lamma.button>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="font-display text-2xl font-bold">Answers · host (default, then reveal)</h3>
                    <div class="grid grid-cols-2 gap-6">
                        @foreach ($planets as $i => [$en, $arabic])
                            @if ($ar)
                                <x-lamma.answer :index="$i" :text="$arabic" lang="ar" />
                            @else
                                <x-lamma.answer :index="$i" :text="$en" :text-alt="$arabic" />
                            @endif
                        @endforeach
                    </div>
                    <div class="grid grid-cols-2 gap-x-6 gap-y-8 pt-3">
                        @foreach ($planets as $i => [$en, $arabic])
                            <x-lamma.answer :index="$i" :text="$ar ? $arabic : $en" :text-alt="$ar ? null : $arabic" :state="$i === 1 ? 'correct' : 'faded'">
                                @if ($i === 1)
                                    <x-slot:pickers>
                                        <x-lamma.avatar name="Sara" :color="0" :size="36" />
                                        <x-lamma.avatar name="Ali" :color="1" :size="36" />
                                    </x-slot:pickers>
                                @endif
                            </x-lamma.answer>
                        @endforeach
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="font-display text-2xl font-bold">Answers · phone (default · selected · locked · faded · correct)</h3>
                    <div class="flex flex-wrap items-start gap-10">
                        <div class="w-[350px] space-y-3">
                            @foreach ($planets as $i => [$en, $arabic])
                                <x-lamma.answer size="phone" :index="$i" :text="$ar ? $arabic : $en" />
                            @endforeach
                        </div>
                        <div class="w-[350px] space-y-3">
                            <x-lamma.answer size="phone" :index="0" :text="$ar ? $planets[0][1] : $planets[0][0]" state="selected" />
                            <x-lamma.answer size="phone" :index="2" :text="$ar ? $planets[2][1] : $planets[2][0]" state="faded" />
                            <x-lamma.answer size="phone" :index="1" :text="$ar ? $planets[1][1] : $planets[1][0]" state="correct" />
                        </div>
                        <x-lamma.answer size="phone" :index="1" :text="$ar ? $planets[1][1] : $planets[1][0]" state="locked" />
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="font-display text-2xl font-bold">Timers · 12 s left of 20, then under 5 s (reload to restart)</h3>
                    <div class="flex flex-wrap items-center gap-10">
                        <x-lamma.timer-ring :ends-at="$endsAt" :seconds="20" />
                        <x-lamma.timer-bar :ends-at="$endsAt" :seconds="20" class="w-[350px]" />
                        <x-lamma.timer-ring :ends-at="$endsSoon" :seconds="20" />
                        <x-lamma.timer-bar :ends-at="$endsSoon" :seconds="20" class="w-[350px]" />
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="font-display text-2xl font-bold">Avatars · players · status</h3>
                    <div class="flex flex-wrap items-center gap-4">
                        @foreach ([36, 48, 72, 120] as $n => $size)
                            <x-lamma.avatar :name="$players[$n % 3]['nickname']" :color="$n" :size="$size" />
                        @endforeach
                        <x-lamma.avatar name="Sara" :color="0" checked />
                        <x-lamma.avatar name="Maya" :color="2" class="opacity-40" />
                        <x-lamma.status-pill ready />
                        <x-lamma.status-pill />
                    </div>
                    <div class="flex flex-wrap items-start gap-10">
                        <ul class="w-[460px] space-y-3.5">
                            @foreach ($players as $n => $player)
                                <x-lamma.player-row :player="$player" :index="$n" />
                            @endforeach
                        </ul>
                        <ul class="w-[350px] rounded-card border-2 border-line px-4 py-1.5">
                            @foreach ($players as $n => $player)
                                <x-lamma.player-row :player="$player" :index="$n" size="phone" :show-language="false" :you="$n === 0" />
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="font-display text-2xl font-bold">Segmented · category toggle</h3>
                    <div class="grid max-w-3xl grid-cols-2 gap-6">
                        <x-lamma.segmented label="Number of questions" :options="[5 => '5', 10 => '10', 15 => '15', 20 => '20']" :selected="10" />
                        <x-lamma.segmented label="Big-screen language" :options="['en' => 'English', 'ar' => ['label' => 'العربية', 'lang' => 'ar'], 'both' => $ar ? 'كلاهما' : 'Both']" selected="both" />
                    </div>
                    <div class="grid max-w-3xl grid-cols-3 gap-3">
                        @foreach ($categories as $n => $category)
                            <x-lamma.category-toggle :category="$category" :selected="$n < 2" />
                        @endforeach
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="font-display text-2xl font-bold">Form field (plain · password with toggle · error) · link tabs</h3>
                    @php
                        $realErrors = $errors ?? new \Illuminate\Support\ViewErrorBag;
                        $demoErrors = (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['email' => [$ar ? 'هذا البريد الإلكتروني غير صالح.' : 'That email address is not valid.']]));
                    @endphp
                    <div class="grid max-w-3xl grid-cols-1 gap-6 md:grid-cols-3">
                        <x-lamma.field name="name" label="Name" dir="auto" placeholder="Full name" />
                        <x-lamma.field name="password" type="password" label="Password" viewable placeholder="Password" />
                        @php view()->share('errors', $demoErrors); @endphp
                        <x-lamma.field name="email" type="email" label="Email address" value="not-an-email" />
                        @php view()->share('errors', $realErrors); @endphp
                    </div>
                    <x-lamma.error>{{ $ar ? 'اختر فئة واحدة على الأقل.' : 'Pick at least one category.' }}</x-lamma.error>
                    <x-lamma.segmented class="max-w-md" selected="login" :options="['login' => ['label' => $ar ? 'تسجيل الدخول' : 'Log in', 'href' => '#login'], 'register' => ['label' => $ar ? 'سجّل الآن' : 'Sign up', 'href' => '#register']]" />
                </div>

                <div class="space-y-4">
                    <h3 class="font-display text-2xl font-bold">Icons · answer shapes · confetti</h3>
                    <div class="flex flex-wrap items-center gap-4">
                        @foreach ($icons as $icon)
                            <x-lamma.icon :name="$icon" :size="32" />
                        @endforeach
                    </div>
                    <div class="flex flex-wrap items-center gap-4">
                        @foreach (['text-coral', 'text-teal', 'text-sun', 'text-navy'] as $i => $tone)
                            <x-lamma.shape :index="$i" :size="40" class="{{ $tone }}" />
                        @endforeach
                    </div>
                    <div class="relative h-48 overflow-hidden rounded-panel bg-navy">
                        <x-lamma.confetti />
                    </div>
                </div>
            </section>
        @endforeach

        @php app()->setLocale($original); @endphp
    </main>
</x-layouts::lamma>
