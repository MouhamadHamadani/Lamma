{{-- Reference: docs/design/screens/host-2-create-room.html. Game rules (availability, one room per host) live in App\Game. --}}
@use('App\Enums\Difficulty')
@use('App\Game\CategorySelection')
@use('App\Game\CategoryVote')
@use('App\Game\RoomSettings')
@use('App\Game\VoteSettings')
@use('App\Support\CategoryStyle')
@use('App\Support\Isolate')
@use('Illuminate\Support\HtmlString')
@php
    $user = auth()->user();
    $both = $hostScreenLocale === 'both';
    $screenLanguage = match ($hostScreenLocale) {
        'ar' => ['العربية', 'ar'],
        'en' => ['English', 'en'],
        default => null,
    };
    $vote = $this->isVote();
    $chosen = CategorySelection::label($categoryIds);
    $expanded = $this->categories->firstWhere('id', $expandedId);
    $picked = $vote ? $this->vote !== null : $categoryIds !== [];
    $short = $picked && $this->available < $questionCount;
    $votes = CategoryVote::voteCount($questionCount, $voteEvery);
    $shortNames = $vote ? CategoryVote::names(array_keys($this->shortfalls)) : [];
@endphp
<div class="flex min-h-dvh flex-col">
    <header class="flex h-22 laptop-short:h-16 shrink-0 items-center justify-between border-b-2 border-line bg-white px-5 lg:px-14">
        <a href="{{ route('home') }}"><x-lamma.logo /></a>

        <div class="flex items-center gap-3 lg:gap-5">
            <a href="{{ route('home') }}#how" class="hidden text-[15px] font-semibold text-ink-muted hover:text-coral-700 sm:block">{{ __('How it works') }}</a>
            <x-lamma.language-switcher />
            <span class="flex items-center gap-2.5">
                <x-lamma.avatar :name="$user->name" :color="2" :size="40" />
                <span class="hidden max-w-40 truncate font-semibold sm:block">{{ $user->name }}</span>
            </span>
        </div>
    </header>

    <main class="mx-auto flex w-full max-w-[1600px] grow flex-col gap-7 px-5 pb-12 pt-9 laptop-short:gap-4 laptop-short:pb-5 laptop-short:pt-4 lg:px-14">
        {{-- The mode switch sits beside the title (not above the card) so a Classic setup is exactly as tall as before; on a short laptop screen it is taller than
             the title, so it is pinned to the row's bottom end and reaches into the space above instead of making the row taller. --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between laptop-short:relative">
            <div class="flex flex-col gap-1.5">
                <h1 class="font-display text-[40px] font-extrabold leading-[1.05] laptop-short:text-4xl lg:text-5xl">{{ __('Set up your game') }}</h1>
                <p class="text-lg text-ink-muted laptop-short:hidden">{{ __('Choose what to play. You can change these before you start.') }}</p>
            </div>
            <x-lamma.segmented
                wire:model.live="mode" :label="__('Game mode')" class="md:w-80 md:shrink-0 laptop-short:absolute laptop-short:bottom-0 laptop-short:end-0"
                :options="['classic' => __('Classic'), 'vote' => __('Vote')]" data-test="mode"
            />
        </div>

        <div class="flex grow flex-col gap-7 xl:flex-row xl:items-stretch">
            <div class="flex min-w-0 grow flex-col gap-[30px] rounded-card border-2 border-line bg-white p-5 laptop-short:gap-4 laptop-short:p-5 lg:p-8">
                @if ($vote)
                {{-- Vote mode: the first category (one), then the pool the players vote between (top-level categories only: a winner brings all its subcategories).
                     On a short laptop screen the first category, its row of chips and "Vote every" share one line, so the page does not scroll. --}}
                <div class="flex flex-col gap-2.5 laptop-short:gap-2" data-test="vote-setup">
                    <div class="flex flex-col gap-2 laptop-short:flex-row laptop-short:items-center laptop-short:gap-3">
                        <div class="flex flex-col gap-0.5 laptop-short:shrink-0">
                            <h2 class="text-base font-bold">{{ __('First category') }}</h2>
                            <p class="text-[13px] font-medium leading-snug text-ink-subtle laptop-short:sr-only" data-test="vote-help">{{ __('The game starts with this category. After every few questions, the players vote on the category of the next ones.') }}</p>
                        </div>
                        <div role="group" aria-label="{{ __('First category') }}" class="-mx-1 flex flex-wrap gap-2 px-1 py-1 laptop-short:min-w-0 laptop-short:grow laptop-short:flex-nowrap laptop-short:overflow-x-auto">
                            @foreach ($this->categories as $category)
                                <x-lamma.subcategory-chip
                                    :category="$category" :tint="CategoryStyle::tint($category->slug)" :selected="$firstCategoryId === $category->id"
                                    wire:key="first-{{ $category->id }}" wire:click="chooseFirst({{ $category->id }})" data-test="first-{{ $category->slug }}"
                                />
                            @endforeach
                        </div>
                        <div class="flex items-center gap-3 laptop-short:shrink-0">
                            <h2 class="shrink-0 text-base font-bold">{{ __('Vote every') }}</h2>
                            <x-lamma.segmented
                                wire:model.live="voteEvery" :label="__('Vote every')" class="w-44"
                                :options="collect(VoteSettings::EVERY)->mapWithKeys(fn ($n) => [$n => (string) $n])->all()" data-test="vote-every"
                            />
                        </div>
                    </div>

                    <div class="flex flex-col gap-0.5 pt-2 laptop-short:flex-row laptop-short:items-baseline laptop-short:gap-3 laptop-short:pt-0">
                        <h2 class="text-base font-bold">{{ __('Categories to vote on') }}</h2>
                        {{-- What is still missing takes the hint's place, so the layout does not grow. --}}
                        @if ($this->voteProblem)
                            <span class="flex items-center gap-1.5 text-[13px] font-semibold text-coral-700" data-test="vote-problem"><x-lamma.icon name="alert" :size="16" class="self-center" />{{ $this->voteProblem }}</span>
                        @else
                            <span class="text-[13px] font-medium text-ink-subtle">{!! __('Pick at least :count, the first one included', ['count' => Isolate::ltr(VoteSettings::MIN_POOL)]) !!}</span>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" data-test="pool">
                        @foreach ($this->categories as $category)
                            @php
                                $isFirst = $firstCategoryId === $category->id;
                                $missing = $this->shortfalls[$category->id] ?? null;
                                $subtitle = match (true) {
                                    $missing !== null => new HtmlString(__(':count questions', ['count' => Isolate::ltr($missing)])),
                                    // The chip row above already says which one is first; a two-line name has no room for the line on a short screen.
                                    $isFirst => new HtmlString('<span class="laptop-short:sr-only">'.e(__('First category')).'</span>'),
                                    default => null,
                                };
                            @endphp
                            <x-lamma.category-toggle
                                :category="$category" :state="$isFirst || in_array($category->id, $poolIds, true) ? 'all' : 'off'" :subtitle="$subtitle"
                                :aria-disabled="$isFirst ? 'true' : null"
                                wire:key="pool-{{ $category->id }}" wire:click="togglePool({{ $category->id }})" data-test="pool-{{ $category->slug }}"
                            />
                        @endforeach
                    </div>

                    @if (! $this->voteProblem && $shortNames !== [])
                        <x-lamma.error data-test="vote-shortfalls">
                            {!! __('Not enough questions for a batch of :count in: :categories.', [
                                'count' => Isolate::ltr(min($voteEvery, $questionCount)),
                                'categories' => collect($shortNames)->map(fn ($name, $id) => e($name).' ('.Isolate::ltr($this->shortfalls[$id]).')')->join(', '),
                            ]) !!}
                            {{ __('Take them out of the pool, or pick another difficulty.') }}
                        </x-lamma.error>
                    @endif
                </div>
                @else
                {{-- Categories --}}
                <div class="flex flex-col gap-2.5">
                    <div class="flex items-baseline justify-between">
                        <h2 class="text-base font-bold">{{ __('Categories') }}</h2>
                        {{-- On a short laptop screen the "pick one" error moves up here (the line under the grid would push the page into a scroll). --}}
                        <span @class(['text-[13px] font-medium text-ink-subtle', 'laptop-short:hidden' => $categoryIds === []])>{{ __('Pick one or more') }}</span>
                        @if ($categoryIds === [])
                            <span class="hidden items-center gap-1.5 text-[13px] font-semibold text-coral-700 laptop-short:flex">
                                <x-lamma.icon name="alert" :size="16" />{{ __('Pick at least one category.') }}
                            </span>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($this->categories as $category)
                            @php
                                $state = CategorySelection::state($category, $categoryIds);
                                $kids = $category->children;
                                $on = $kids->filter(fn ($child) => in_array($child->id, $categoryIds, true))->count();
                            @endphp
                            <x-lamma.category-toggle
                                :category="$category" :state="$state"
                                :subtitle="$state === 'partial' ? new HtmlString(__(':count of :total', ['count' => Isolate::ltr($on), 'total' => Isolate::ltr($kids->count())])) : null"
                                :expand="$kids->isEmpty() ? null : ['wire:click' => 'toggleExpanded('.$category->id.')']"
                                :expanded="$expandedId === $category->id" :panel="'subcategories-'.$category->id"
                                wire:key="category-{{ $category->id }}" wire:click="toggleCategory({{ $category->id }})"
                                data-test="category-{{ $category->slug }}"
                            />
                        @endforeach
                    </div>

                    {{-- The subcategories of the category being expanded. On a short laptop screen the label sits beside one row of chips that scrolls sideways, so the page never does. --}}
                    @if ($expanded)
                        <div
                            id="subcategories-{{ $expanded->id }}" wire:key="subcategories-{{ $expanded->id }}" role="group"
                            aria-label="{{ __('Subcategories of :category', ['category' => $expanded->name]) }}" data-test="subcategories"
                            class="flex flex-col gap-2 rounded-2xl border-2 border-line bg-cream px-3 py-2.5 laptop-short:flex-row laptop-short:items-center laptop-short:gap-3 laptop-short:py-1.5"
                        >
                            <p class="text-[13px] font-semibold leading-tight text-ink-muted laptop-short:max-w-40 laptop-short:shrink-0"><span class="font-bold text-navy">{{ $expanded->name }}</span><span class="laptop-short:hidden"> · {{ __('Pick all of it, or only some') }}</span></p>
                            <div class="-mx-1 flex flex-wrap gap-2 px-1 py-1 laptop-short:min-w-0 laptop-short:grow laptop-short:flex-nowrap laptop-short:overflow-x-auto">
                                @foreach ($expanded->children as $child)
                                    <x-lamma.subcategory-chip
                                        :category="$child" :tint="CategoryStyle::tint($expanded->slug)"
                                        :selected="in_array($child->id, $categoryIds, true) || in_array($expanded->id, $categoryIds, true)"
                                        wire:key="subcategory-{{ $child->id }}" wire:click="toggleSubcategory({{ $child->id }})"
                                        data-test="subcategory-{{ $child->slug }}"
                                    />
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($categoryIds === [])
                        <x-lamma.error class="laptop-short:hidden">{{ __('Pick at least one category.') }}</x-lamma.error>
                    @endif
                </div>
                @endif

                <div class="grid gap-[30px] md:grid-cols-2 md:gap-7 xl:grid-cols-6 xl:items-end laptop-short:gap-x-7 laptop-short:gap-y-3">
                    <div class="flex flex-col gap-2.5 xl:col-span-2">
                        <h2 class="text-base font-bold">{{ __('Number of questions') }}</h2>
                        <x-lamma.segmented wire:model.live="questionCount" :label="__('Number of questions')" :options="array_combine(RoomSettings::QUESTION_COUNTS, RoomSettings::QUESTION_COUNTS)" />
                    </div>

                    <div class="flex flex-col gap-2.5 xl:col-span-2">
                        <h2 class="text-base font-bold">{{ __('Time per question') }}</h2>
                        <x-lamma.segmented
                            wire:model.live="secondsPerQuestion" :label="__('Time per question')"
                            :options="collect(RoomSettings::SECONDS_PER_QUESTION)->mapWithKeys(fn ($s) => [$s => __(':seconds s', ['seconds' => $s])])->all()"
                        />
                    </div>

                    {{-- Selfies (App\Game\PlayerPhoto). Off = no photo step, no consent line, no avatar_url anywhere. --}}
                    <div class="order-last flex flex-col gap-2.5 xl:order-none md:col-span-2 md:flex-row md:items-center md:justify-between md:gap-6 xl:col-span-2 xl:flex-col xl:items-stretch xl:justify-start xl:gap-2.5" data-test="photos-setting">
                        <div class="flex flex-col gap-0.5 xl:gap-1">
                            <h2 class="text-base font-bold">{{ __('Player photos') }}</h2>
                            {{-- On a short laptop screen the line is for screen readers only, like the other hints here: the row must not grow. --}}
                            <p class="text-[13px] font-medium leading-snug text-ink-subtle laptop-short:sr-only">{{ __('Players can take a selfie to show next to their name.') }}</p>
                        </div>
                        <x-lamma.segmented wire:model.live="photos" :label="__('Player photos')" :options="['on' => __('On'), 'off' => __('Off')]" class="md:w-64 md:shrink-0 xl:w-auto" />
                    </div>

                    <div class="flex flex-col gap-2.5 xl:col-span-2">
                        <h2 class="text-base font-bold">{{ __('Difficulty') }}</h2>
                        <x-lamma.segmented
                            wire:model.live="difficulty" :label="__('Difficulty')"
                            :options="['easy' => Difficulty::Easy->getLabel(), 'medium' => Difficulty::Medium->getLabel(), 'hard' => Difficulty::Hard->getLabel(), 'mixed' => __('Mixed')]"
                        />
                    </div>

                    <div class="flex flex-col gap-2.5 xl:col-span-2">
                        <h2 class="text-base font-bold">{{ __('Big-screen language') }}</h2>
                        <x-lamma.segmented
                            wire:model.live="hostScreenLocale" :label="__('Big-screen language')"
                            :options="['en' => ['label' => 'English', 'lang' => 'en'], 'ar' => ['label' => 'العربية', 'lang' => 'ar'], 'both' => __('Both')]"
                        />
                    </div>

                    {{-- The longest label on the page: on a short laptop screen it sits beside its switch, so the row does not grow. --}}
                    <div class="order-last flex flex-col gap-2.5 xl:order-none md:col-span-2 md:flex-row md:items-center md:justify-between md:gap-6 xl:col-span-2 xl:flex-col xl:items-stretch xl:justify-start xl:gap-2.5 laptop-short:flex-row laptop-short:items-center laptop-short:gap-2" data-test="double-final-setting">
                        <h2 class="text-base font-bold laptop-short:text-sm laptop-short:leading-tight">{{ __('Double points on the last question') }}</h2>
                        <x-lamma.segmented wire:model.live="doubleFinal" :label="__('Double points on the last question')" :options="['on' => __('On'), 'off' => __('Off')]" class="md:w-64 md:shrink-0 xl:w-auto laptop-short:w-28" />
                    </div>
                </div>
            </div>

            {{-- Live summary --}}
            <aside class="flex flex-col gap-1.5 rounded-card border-3 border-navy bg-white p-7 shadow-sticker-lg xl:w-[380px] xl:shrink-0 laptop-short:p-5" aria-labelledby="summary-title">
                <h2 id="summary-title" class="pb-1.5 font-display text-[28px] font-extrabold">{{ __('Your game') }}</h2>

                @foreach ([
                    ...($vote ? [
                        // The pool size shares the mode's row: the card must stay as short as a Classic one.
                        [__('Mode'), $this->vote && count($this->vote->poolIds) >= VoteSettings::MIN_POOL ? __('Vote · :count categories', ['count' => count($this->vote->poolIds)]) : __('Vote')],
                        [__('First category'), $firstCategoryId ? (CategoryVote::names([$firstCategoryId])[$firstCategoryId] ?? '—') : '—'],
                        [__('Votes'), new HtmlString('<span data-test="vote-summary">'.($votes === 1
                            ? __('Every :every questions · 1 vote', ['every' => Isolate::ltr($voteEvery)])
                            : __('Every :every questions · :count votes', ['every' => Isolate::ltr($voteEvery), 'count' => Isolate::ltr($votes)])).'</span>')],
                    ] : [
                        [__('Categories'), $chosen === '' ? '—' : $chosen],
                    ]),
                    [__('Questions'), new HtmlString($questionCount.(! $picked ? '' : ' <span class="text-[13px] font-medium text-ink-subtle" data-test="available">('.__(':count available', ['count' => Isolate::ltr($this->available)]).')</span>'))],
                    [__('Time'), __(':seconds s each', ['seconds' => $secondsPerQuestion])],
                    [__('Last question'), $doubleFinal === 'off' ? __('Normal points') : __('Double points')],
                    [__('Difficulty'), Difficulty::tryFrom($difficulty)?->getLabel() ?? __('Mixed')],
                    [__('Player photos'), $photos === 'off' ? __('Off') : __('On')],
                ] as [$label, $value])
                    <div @class(['flex justify-between gap-3 border-b border-dashed border-line py-3.5 text-base', 'laptop-short:py-2' => ! $vote, 'laptop-short:py-1' => $vote])>
                        <span class="text-ink-muted">{{ $label }}</span>
                        <span class="text-end font-bold">{{ $value }}</span>
                    </div>
                @endforeach
                <div @class(['flex justify-between gap-3 border-b border-dashed border-line py-3.5 text-base', 'laptop-short:py-2' => ! $vote, 'laptop-short:py-1' => $vote])>
                    <span class="text-ink-muted">{{ __('Big screen') }}</span>
                    <span class="text-end font-bold">
                        @if ($screenLanguage)
                            <span lang="{{ $screenLanguage[1] }}">{{ $screenLanguage[0] }}</span>
                        @else
                            English + <span lang="ar">العربية</span>
                        @endif
                    </span>
                </div>

                <div class="grow"></div>

                @if ($short || $errors->has('questionCount'))
                    <x-lamma.error class="pt-3" data-test="not-enough-questions">
                        {{ $errors->first('questionCount') ?: __('Only :available questions match this choice, but you picked :count.', ['available' => $this->available, 'count' => $questionCount]) }}
                        {{ __('Pick more categories, another difficulty, or fewer questions.') }}
                    </x-lamma.error>
                @endif

                @if (! $short && $this->freshLeft !== null && $this->freshLeft < $questionCount)
                    <p class="mt-3 flex items-start gap-2 rounded-xl bg-tint-sun px-3.5 py-2.5 text-sm font-semibold" data-test="few-fresh-questions">
                        <x-lamma.icon name="alert" :size="18" class="mt-0.5" />
                        <span>{!! __('Only :count fresh questions left for this group.', ['count' => Isolate::ltr($this->freshLeft)]) !!}</span>
                    </p>
                @endif

                <x-lamma.button
                    size="lg" icon="play" class="mt-3 w-full"
                    :disabled="! $this->canCreate" wire:click="create" wire:loading.attr="disabled" wire:target="create"
                    data-test="create-room-button"
                >{{ __('Create room') }}</x-lamma.button>

                {{-- A Vote setup's summary is two rows longer: on a short laptop screen this line makes room for them. --}}
                <p @class(['pt-2.5 text-center text-sm text-ink-subtle', 'laptop-short:hidden' => $vote])>
                    {{ $picked ? __("You'll get a room code to share.") : ($vote ? __('Pick the first category to continue.') : __('Pick at least one category to continue.')) }}
                </p>
            </aside>
        </div>
    </main>
</div>
