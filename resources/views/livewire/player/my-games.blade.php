{{-- /me/games: totals, then one card per saved game (newest first). Only games tied to the account are listed. --}}
@use('App\Game\Scoreboard')
<div class="mx-auto flex min-h-dvh w-full max-w-3xl flex-col gap-8 px-5 pb-12">
    <header class="flex h-[72px] shrink-0 items-center justify-between gap-3">
        <a href="{{ route('home') }}"><x-lamma.logo /></a>
        <nav class="flex items-center gap-2 text-sm font-semibold" aria-label="{{ __('Account') }}">
            <x-lamma.language-switcher />
            <a href="{{ route('rooms.create') }}" class="hidden h-11 items-center rounded-input px-3 hover:bg-tint-navy sm:flex">{{ __('Host a game') }}</a>
            <a href="{{ route('profile.edit') }}" class="hidden h-11 items-center rounded-input px-3 hover:bg-tint-navy sm:flex">{{ __('Settings') }}</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="h-11 rounded-input px-3 hover:bg-tint-navy" data-test="logout-button">{{ __('Log out') }}</button>
            </form>
        </nav>
    </header>

    <main class="flex flex-col gap-8">
        <div class="flex flex-col gap-1">
            <h1 class="font-display text-[clamp(36px,6vw,52px)] font-extrabold leading-tight">{{ __('My games') }}</h1>
            <p class="text-lg text-ink-muted">{{ $user->name }}</p>
        </div>

        <dl class="grid grid-cols-3 gap-3" data-test="totals">
            <div class="flex flex-col gap-1 rounded-tile border-2 border-line bg-white px-4 py-4">
                <dt class="text-sm font-bold text-ink-muted">{{ __('Games played') }}</dt>
                <dd class="font-display text-[clamp(28px,5vw,40px)] font-extrabold leading-none" dir="ltr" data-test="total-played">{{ $totals['played'] }}</dd>
            </div>
            <div class="flex flex-col gap-1 rounded-tile border-2 border-line bg-white px-4 py-4">
                <dt class="text-sm font-bold text-ink-muted">{{ __('Wins') }}</dt>
                <dd class="font-display text-[clamp(28px,5vw,40px)] font-extrabold leading-none" dir="ltr" data-test="total-wins">{{ $totals['wins'] }}</dd>
            </div>
            <div class="flex flex-col gap-1 rounded-tile border-2 border-line bg-white px-4 py-4">
                <dt class="text-sm font-bold text-ink-muted">{{ __('Correct answers') }}</dt>
                <dd class="font-display text-[clamp(28px,5vw,40px)] font-extrabold leading-none" dir="ltr" data-test="total-rate">{{ $totals['rate'] === null ? '—' : $totals['rate'].'%' }}</dd>
                @if ($totals['questions'] > 0)
                    <dd class="text-xs font-semibold text-ink-subtle" data-test="total-correct"><bdi dir="ltr">{{ __(':correct of :total', ['correct' => $totals['correct'], 'total' => $totals['questions']]) }}</bdi></dd>
                @endif
            </div>
        </dl>

        @if ($games->isEmpty())
            <div class="flex flex-col items-start gap-4 rounded-card border-2 border-dashed border-line-strong px-6 py-8" data-test="empty">
                <h2 class="font-display text-2xl font-extrabold">{{ __('No saved games yet') }}</h2>
                <p class="text-ink-muted">{{ __('Games you play while logged in, or save from the results screen, appear here.') }}</p>
                <x-lamma.button :href="route('join')" icon="play">{{ __('Join a game') }}</x-lamma.button>
            </div>
        @else
            <ul class="flex flex-col gap-3" data-test="games">
                @foreach ($games as $game)
                    @php
                        $rank = (int) $game->rank;
                        $tile = $rank === 1 ? 'bg-sun' : ($rank === 2 ? 'bg-teal' : 'bg-coral');
                        $names = collect($game->room->settings->categoryIds)->map(fn ($id) => $categories[$id]->name ?? null)->filter()->values();
                    @endphp
                    <li class="flex items-center gap-4 rounded-tile border-2 border-line bg-white px-4 py-3.5" wire:key="game-{{ $game->id }}" data-test="game" data-rank="{{ $rank }}">
                        <span class="{{ $tile }} flex size-[60px] shrink-0 -rotate-3 items-center justify-center rounded-2xl border-3 border-navy font-display text-2xl font-extrabold shadow-sticker-sm" dir="ltr" role="img" aria-label="{{ __('Your place: :rank', ['rank' => Scoreboard::ordinal($rank)]) }}">{{ $rank }}<span class="text-sm">{{ Scoreboard::suffix($rank) }}</span></span>

                        <div class="flex min-w-0 grow flex-col gap-1">
                            <div class="flex flex-wrap items-baseline gap-x-3">
                                <span class="font-display text-xl font-extrabold" dir="ltr" data-test="score">{{ __(':points pts', ['points' => $game->score]) }}</span>
                                <time datetime="{{ $game->room->finished_at?->toDateString() }}" class="text-sm font-semibold text-ink-muted" data-test="date">{{ $game->room->finished_at?->locale(app()->getLocale())->translatedFormat('j F Y') }}</time>
                            </div>
                            <p class="truncate text-sm text-ink-muted" data-test="categories">{{ $names->join(' · ') }}</p>
                            <p class="text-xs font-semibold text-ink-subtle">
                                <bdi dir="ltr">{{ __(':correct of :total', ['correct' => $game->correct_count, 'total' => $game->questions_count]) }}</bdi> ·
                                {{ __('Players: :count', ['count' => $game->players_count]) }}
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($games->hasPages())
                <div class="flex items-center justify-between gap-3">
                    <x-lamma.button variant="outline" wire:click="previousPage" :disabled="$games->onFirstPage()" data-test="previous-page">{{ __('Previous') }}</x-lamma.button>
                    <span class="text-sm font-semibold text-ink-muted" dir="ltr">{{ $games->currentPage() }} / {{ $games->lastPage() }}</span>
                    <x-lamma.button variant="outline" wire:click="nextPage" :disabled="! $games->hasMorePages()" data-test="next-page">{{ __('Next') }}</x-lamma.button>
                </div>
            @endif
        @endif
    </main>
</div>
