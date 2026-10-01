{{-- Reference: docs/design/screens/host-6-podium.html. Navy, confetti, the winner(s) on the left, the podium (2 · 1 · 3) on the right. Ties share a step;
     a step nobody stands on is left out; if nobody scored there is no winner. Bars grow 3rd, 2nd, 1st (600ms, 150ms apart), then the crown drops. --}}
@use('App\Support\Isolate')
@php
    $names = collect($winners)->map(fn (array $winner) => '<bdi dir="ltr">'.e($winner['nickname']).'</bdi>')->join(' &amp; ');
    [$headline, $headlineAr] = match (count($winners)) {
        0 => [__('No winner this time'), __('No winner this time', [], 'ar')],
        1 => [__(':name wins!', ['name' => $names]), __(':name wins!', ['name' => $names], 'ar')],
        2 => [__(':names win!', ['names' => $names]), __(':names win!', ['names' => $names], 'ar')],
        default => [__("It's a tie!"), __("It's a tie!", [], 'ar')],
    };
    $gutter = 'px-[clamp(24px,6.7vw,96px)]';
    $barHeight = [1 => 'h-[clamp(130px,30dvh,270px)]', 2 => 'h-[clamp(100px,21dvh,190px)]', 3 => 'h-[clamp(80px,15.5dvh,140px)]'];
    $barColor = [1 => 'bg-sun', 2 => 'bg-teal', 3 => 'bg-coral'];
    $barDelay = [3 => 0, 2 => 750, 1 => 1500]; // 600ms each, 150ms apart: third, second, first
@endphp
<div class="relative flex h-dvh flex-col overflow-hidden bg-navy text-cream" data-test="host-results" x-init="$store.sound.play('podium')">
    @if ($winners)
        <x-lamma.confetti :count="12" />
    @endif

    <header class="relative flex h-[clamp(64px,9.8dvh,88px)] shrink-0 items-center justify-between gap-4 {{ $gutter }}">
        <x-lamma.logo :on-dark="true" />
        <div class="flex items-center gap-3">
            <x-lamma.sound-toggle :on-dark="true" />
            <span dir="ltr" class="inline-flex h-10 items-center gap-2 rounded-chip bg-navy-700 px-3.5 text-sm font-bold">
                {{ __('Room') }} <span class="font-display text-lg font-extrabold tracking-[3px]">{{ $room->code }}</span>
            </span>
        </div>
    </header>

    <main class="relative flex min-h-0 grow items-end justify-between gap-10 pb-[clamp(24px,6dvh,64px)] {{ $gutter }}">
        <section class="flex w-[clamp(320px,36vw,520px)] shrink-0 flex-col gap-[clamp(12px,2.4dvh,22px)] self-center">
            <span class="text-base font-bold text-sun">{{ __('Game over · :count questions', ['count' => $questionCount]) }}</span>
            <h1 class="text-balance font-display text-[clamp(44px,6.4vw,92px)] font-extrabold leading-[1.05] motion-safe:animate-fade-up" data-test="headline">{!! $headline !!}</h1>
            @if ($both)
                <p lang="ar" dir="rtl" class="text-balance text-end font-display text-[clamp(26px,2.5vw,36px)] font-bold leading-tight text-coral" data-test="headline-ar">{!! $headlineAr !!}</p>
            @endif
            @if (count($winners) > 2)
                <p class="text-xl font-semibold" data-test="tied-names">{!! $names !!}</p>
            @endif
            <p class="text-[19px] text-ink-on-dark">{{ __('Great game, everyone. Same players, another round?') }}</p>

            <div class="flex flex-wrap items-center gap-3.5">
                <x-lamma.button size="lg" icon="play" :on-dark="true" wire:click="playAgain" wire:loading.attr="disabled" wire:target="playAgain" data-test="play-again">{{ __('Play again') }}</x-lamma.button>
                <x-lamma.button size="lg" variant="outline" :on-dark="true" :href="route('rooms.create')" data-test="new-game">{{ __('New game') }}</x-lamma.button>
            </div>
            @if ($error)
                <p class="text-base font-semibold text-sun" role="alert" data-test="play-again-error">{{ $error }}</p>
            @endif
            <a href="{{ route('home') }}" class="min-h-11 text-base font-semibold text-ink-on-dark underline-offset-4 hover:underline">{{ __('Back to home') }}</a>
        </section>

        <section class="flex min-w-0 items-end justify-center gap-4" aria-label="{{ __('Podium') }}" data-test="podium">
            @forelse ($winners ? $steps : [] as $step)
                @php
                    $rank = $step['rank'];
                    $players = collect($step['players']);
                    $size = $rank === 1 ? 104 : 84;
                @endphp
                <div class="flex w-[clamp(110px,11.8vw,170px)] flex-col items-center gap-2.5 text-center" data-rank="{{ $rank }}" data-test="podium-step">
                    @if ($rank === 1)
                        <x-lamma.icon name="crown" :size="48" :stroke="2" class="fill-sun text-sun motion-safe:animate-drop-in motion-reduce:animate-fade-in" style="animation-delay: 2100ms" data-test="crown" />
                    @endif
                    <div class="flex -space-x-3 motion-safe:animate-fade-in rtl:space-x-reverse" style="animation-delay: {{ $barDelay[$rank] + 300 }}ms">
                        @foreach ($players->take(3) as $player)
                            <x-lamma.avatar :name="$player['nickname']" :color="$colors[$player['id']] ?? 0" :size="$size" />
                        @endforeach
                        @if ($players->count() > 3)
                            <span dir="ltr" class="flex shrink-0 items-center justify-center rounded-full border-3 border-navy bg-white font-display font-extrabold text-navy" style="width: {{ $size }}px; height: {{ $size }}px">+{{ $players->count() - 3 }}</span>
                        @endif
                    </div>
                    <span class="line-clamp-2 font-display text-[clamp(18px,2vw,30px)] font-bold leading-tight motion-safe:animate-fade-in" style="animation-delay: {{ $barDelay[$rank] + 300 }}ms">
                        {!! $players->map(fn (array $player) => '<bdi dir="ltr">'.e($player['nickname']).'</bdi>')->join(' · ') !!}
                    </span>
                    <span class="text-base font-semibold text-ink-on-dark motion-safe:animate-fade-in" style="animation-delay: {{ $barDelay[$rank] + 300 }}ms">{!! __(':points pts', ['points' => Isolate::ltr($players->first()['total'])]) !!}</span>
                    <div
                        class="{{ $barColor[$rank] }} {{ $barHeight[$rank] }} flex w-full origin-bottom justify-center rounded-t-[22px] pt-3 font-display text-[clamp(40px,5vw,72px)] font-extrabold leading-none text-navy motion-safe:animate-bar-grow motion-reduce:animate-fade-in"
                        style="animation-delay: {{ $barDelay[$rank] }}ms"
                    ><span dir="ltr">{{ $rank }}</span></div>
                </div>
            @empty
                <p class="max-w-xs text-center font-display text-3xl font-bold text-ink-on-dark" data-test="no-winner">{{ __('Nobody scored this time.') }}</p>
            @endforelse
        </section>
    </main>
</div>
