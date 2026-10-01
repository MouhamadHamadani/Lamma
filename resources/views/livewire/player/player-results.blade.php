{{-- Reference: docs/design/screens/player-6-results.html. Designed at 390x844, a phone-width column on bigger screens. The player's own language.
     Rank tile: sun for 1st, teal for 2nd, coral for 3rd and below. Own row in the leaderboard has a 3px navy border. --}}
@use('App\Game\Scoreboard')
@php
    $name = '<bdi dir="ltr">'.e($me->nickname).'</bdi>';
    $headline = match (true) {
        $rank === 1 && $total > 0 => __('You won, :name!', ['name' => $name]),
        $rank === 2 => __('So close, :name!', ['name' => $name]),
        $rank === 3 => __('Nice one, :name!', ['name' => $name]),
        default => __('Good game, :name!', ['name' => $name]),
    };
    $tile = $rank === 1 ? 'bg-sun' : ($rank === 2 ? 'bg-teal' : 'bg-coral');
@endphp
<div class="relative mx-auto flex min-h-dvh w-full max-w-md flex-col px-5 pb-[max(1.75rem,env(safe-area-inset-bottom))]" wire:poll.5s="follow" data-test="player-results" data-rank="{{ $rank }}">
    @if ($rank <= 3)
        <x-lamma.confetti :count="3" />
    @endif

    <header class="relative flex h-16 shrink-0 items-center justify-between">
        <x-lamma.logo size="sm" />
        <x-lamma.room-code :code="$room->code" size="chip" data-test="room-code" />
    </header>

    <main class="relative flex grow flex-col items-center gap-[18px] pb-2 pt-4 text-center">
        <span class="text-sm font-bold text-coral-700">{{ __('Game over') }}</span>

        <div
            class="{{ $tile }} flex size-[150px] -rotate-4 flex-col items-center justify-center rounded-[32px] border-3 border-navy text-navy shadow-sticker-lg motion-safe:animate-reveal"
            data-test="rank-tile" role="img" aria-label="{{ __('Your place: :rank', ['rank' => Scoreboard::ordinal($rank)]) }}"
        >
            <span class="font-display text-[72px] font-extrabold leading-none" dir="ltr">{{ $rank }}@if (Scoreboard::suffix($rank))<span class="text-[32px]">{{ Scoreboard::suffix($rank) }}</span>@endif</span>
        </div>
        @if ($tied)
            <x-lamma.chip tone="navy" size="sm" data-test="tied">{{ __('Tied for :rank', ['rank' => Scoreboard::ordinal($rank)]) }}</x-lamma.chip>
        @endif

        <div class="flex flex-col gap-1">
            <h1 class="font-display text-[32px] font-extrabold leading-tight motion-safe:animate-fade-up" data-test="headline">{!! $headline !!}</h1>
            <p class="text-base text-ink-muted" data-test="score-line">{!! __(':points points · :correct of :total correct', ['points' => '<bdi dir="ltr">'.$total.'</bdi>', 'correct' => '<bdi dir="ltr">'.$correct.'</bdi>', 'total' => '<bdi dir="ltr">'.$questions.'</bdi>']) !!}</p>
        </div>

        <ol class="flex w-full flex-col gap-2.5" aria-label="{{ __('Leaderboard') }}" data-test="leaderboard">
            @foreach ($ranking as $index => $entry)
                @php $you = $entry['player']['id'] === $me->id; @endphp
                <li
                    @class(['flex h-[60px] items-center gap-3 rounded-2xl bg-white px-3.5 text-start', 'border-3 border-navy' => $you, 'border-2 border-line' => ! $you])
                    @if ($you) data-you aria-current="true" @endif data-rank="{{ $entry['rank'] }}"
                >
                    <span class="w-5 text-center font-display text-xl font-extrabold" dir="ltr">{{ $entry['rank'] }}</span>
                    <x-lamma.avatar :name="$entry['player']['nickname']" :color="$colors[$entry['player']['id']] ?? $index" :size="36" />
                    <span class="grow truncate text-[17px] font-bold"><bdi dir="ltr">{{ $entry['player']['nickname'] }}</bdi>@if ($you) <span class="font-semibold text-ink-muted">{{ __('(you)') }}</span>@endif</span>
                    <span class="font-display text-xl font-extrabold" dir="ltr">{{ $entry['total'] }}</span>
                </li>
            @endforeach
        </ol>

        <div class="w-full" data-test="save-card">
            @if ($saved)
                <div class="flex items-center gap-3.5 rounded-tile border-2 border-line bg-tint-teal px-[18px] py-4 text-start" data-state="saved">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full border-3 border-navy bg-teal"><x-lamma.icon name="check" :size="20" :stroke="3" /></span>
                    <div class="flex grow flex-col">
                        <span class="font-display text-lg font-extrabold">{{ __('Saved to your profile') }}</span>
                        <a href="{{ route('me.games') }}" class="min-h-6 text-sm font-semibold text-coral-700 underline underline-offset-4">{{ __('See my games') }}</a>
                    </div>
                </div>
            @elseif (! $loggedIn)
                <div class="flex flex-col gap-3.5 rounded-tile border-2 border-line bg-white px-[18px] py-4 text-start" data-state="guest">
                    <div class="flex flex-col gap-1">
                        <span class="font-display text-lg font-extrabold">{{ __('Save your score') }}</span>
                        <p class="text-[15px] text-ink-muted">{{ __('Log in or sign up to save this game to your profile.') }}</p>
                    </div>
                    <div class="flex gap-3">
                        <x-lamma.button :href="route('play.save', [$room->code, 'login'])" class="grow" data-test="save-login">{{ __('Log in') }}</x-lamma.button>
                        <x-lamma.button :href="route('play.save', [$room->code, 'register'])" variant="outline" class="grow" data-test="save-register">{{ __('Sign up') }}</x-lamma.button>
                    </div>
                </div>
            @else
                <div class="rounded-tile border-2 border-line bg-white px-[18px] py-4 text-start" data-state="unsaved">
                    <p class="text-[15px] text-ink-muted">{{ __("This game was played before you logged in, so it can't be added to your profile.") }}</p>
                </div>
            @endif
        </div>

        <div class="grow"></div>

        <p class="text-sm text-ink-subtle">{{ __("Stay here. If the host starts another round, you're in.") }}</p>
        <x-lamma.button :href="route('home')" variant="outline" class="w-full" data-test="leave-button">{{ __('Leave room') }}</x-lamma.button>
    </main>
</div>
