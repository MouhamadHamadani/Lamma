{{-- Reference: docs/design/screens/host-2-create-room.html. Game rules (availability, one room per host) live in App\Game. --}}
@use('App\Enums\Difficulty')
@use('App\Game\RoomSettings')
@php
    $user = auth()->user();
    $both = $hostScreenLocale === 'both';
    $screenLanguage = match ($hostScreenLocale) {
        'ar' => ['العربية', 'ar'],
        'en' => ['English', 'en'],
        default => null,
    };
    $chosen = $this->categories->whereIn('id', $categoryIds)->pluck('name');
    $short = $categoryIds !== [] && $this->available < $questionCount;
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
        <div class="flex flex-col gap-1.5">
            <h1 class="font-display text-[40px] font-extrabold leading-[1.05] laptop-short:text-4xl lg:text-5xl">{{ __('Set up your game') }}</h1>
            <p class="text-lg text-ink-muted laptop-short:hidden">{{ __('Choose what to play. You can change these before you start.') }}</p>
        </div>

        <div class="flex grow flex-col gap-7 xl:flex-row xl:items-stretch">
            <div class="flex grow flex-col gap-[30px] rounded-card border-2 border-line bg-white p-5 laptop-short:gap-4 laptop-short:p-5 lg:p-8">
                {{-- Categories --}}
                <div class="flex flex-col gap-2.5">
                    <div class="flex items-baseline justify-between">
                        <h2 class="text-base font-bold">{{ __('Categories') }}</h2>
                        <span class="text-[13px] font-medium text-ink-subtle">{{ __('Pick one or more') }}</span>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($this->categories as $category)
                            <x-lamma.category-toggle
                                :category="$category" :selected="in_array($category->id, $categoryIds)"
                                wire:key="category-{{ $category->id }}" wire:click="toggleCategory({{ $category->id }})"
                            />
                        @endforeach
                    </div>

                    @if ($categoryIds === [])
                        <x-lamma.error>{{ __('Pick at least one category.') }}</x-lamma.error>
                    @endif
                </div>

                <div class="grid gap-[30px] md:grid-cols-2 md:gap-7 laptop-short:gap-x-7 laptop-short:gap-y-3">
                    <div class="flex flex-col gap-2.5">
                        <h2 class="text-base font-bold">{{ __('Number of questions') }}</h2>
                        <x-lamma.segmented wire:model.live="questionCount" :label="__('Number of questions')" :options="array_combine(RoomSettings::QUESTION_COUNTS, RoomSettings::QUESTION_COUNTS)" />
                    </div>

                    <div class="flex flex-col gap-2.5">
                        <h2 class="text-base font-bold">{{ __('Time per question') }}</h2>
                        <x-lamma.segmented
                            wire:model.live="secondsPerQuestion" :label="__('Time per question')"
                            :options="collect(RoomSettings::SECONDS_PER_QUESTION)->mapWithKeys(fn ($s) => [$s => __(':seconds s', ['seconds' => $s])])->all()"
                        />
                    </div>

                    <div class="flex flex-col gap-2.5">
                        <h2 class="text-base font-bold">{{ __('Difficulty') }}</h2>
                        <x-lamma.segmented
                            wire:model.live="difficulty" :label="__('Difficulty')"
                            :options="['easy' => Difficulty::Easy->getLabel(), 'medium' => Difficulty::Medium->getLabel(), 'hard' => Difficulty::Hard->getLabel(), 'mixed' => __('Mixed')]"
                        />
                    </div>

                    <div class="flex flex-col gap-2.5">
                        <h2 class="text-base font-bold">{{ __('Big-screen language') }}</h2>
                        <x-lamma.segmented
                            wire:model.live="hostScreenLocale" :label="__('Big-screen language')"
                            :options="['en' => ['label' => 'English', 'lang' => 'en'], 'ar' => ['label' => 'العربية', 'lang' => 'ar'], 'both' => __('Both')]"
                        />
                    </div>
                </div>
            </div>

            {{-- Live summary --}}
            <aside class="flex flex-col gap-1.5 rounded-card border-3 border-navy bg-white p-7 shadow-sticker-lg xl:w-[380px] xl:shrink-0 laptop-short:p-5" aria-labelledby="summary-title">
                <h2 id="summary-title" class="pb-1.5 font-display text-[28px] font-extrabold">{{ __('Your game') }}</h2>

                @foreach ([
                    [__('Categories'), $chosen->isEmpty() ? '—' : $chosen->join(', ')],
                    [__('Questions'), $questionCount],
                    [__('Time'), __(':seconds s each', ['seconds' => $secondsPerQuestion])],
                    [__('Difficulty'), Difficulty::tryFrom($difficulty)?->getLabel() ?? __('Mixed')],
                ] as [$label, $value])
                    <div class="flex justify-between gap-3 border-b border-dashed border-line py-3.5 text-base laptop-short:py-2">
                        <span class="text-ink-muted">{{ $label }}</span>
                        <span class="text-end font-bold">{{ $value }}</span>
                    </div>
                @endforeach
                <div class="flex justify-between gap-3 border-b border-dashed border-line py-3.5 text-base laptop-short:py-2">
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

                <x-lamma.button
                    size="lg" icon="play" class="mt-3 w-full"
                    :disabled="! $this->canCreate" wire:click="create" wire:loading.attr="disabled" wire:target="create"
                    data-test="create-room-button"
                >{{ __('Create room') }}</x-lamma.button>

                <p class="pt-2.5 text-center text-sm text-ink-subtle">
                    {{ $categoryIds === [] ? __('Pick at least one category to continue.') : __("You'll get a room code to share.") }}
                </p>
            </aside>
        </div>
    </main>
</div>
