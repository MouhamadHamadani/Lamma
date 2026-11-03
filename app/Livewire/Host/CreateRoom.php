<?php

namespace App\Livewire\Host;

use App\Enums\Difficulty;
use App\Enums\GameMode;
use App\Enums\HostScreenLocale;
use App\Game\CategorySelection;
use App\Game\CategoryVote;
use App\Game\NotEnoughQuestions;
use App\Game\PlayerIdentity;
use App\Game\QuestionPicker;
use App\Game\QuestionPool;
use App\Game\RoomManager;
use App\Game\RoomSettings;
use App\Game\VoteSettings;
use App\Models\Category;
use App\Support\Analytics;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The host chooses what to play; the game rules (question availability, one room per host) live in App\Game.
 *
 * The categories are the top-level ones. Choosing one means all of it (its own questions and every subcategory's), stored as its id;
 * choosing only some of its subcategories stores just those ids (CategorySelection reads them, QuestionPool resolves them).
 *
 * @property-read Collection<int, Category> $categories
 * @property-read RoomSettings $settings
 * @property-read int $available
 * @property-read bool $canCreate
 * @property-read int|null $freshLeft
 * @property-read VoteSettings|null $vote
 * @property-read array<int, int> $shortfalls
 * @property-read string|null $voteProblem
 */
#[Layout('layouts::lamma')]
class CreateRoom extends Component
{
    /** @var list<int> */
    public array $categoryIds = [];

    /** The top-level category whose subcategories are showing, if any (one at a time keeps the screen from growing). */
    public ?int $expandedId = null;

    public int $questionCount = 10;

    public int $secondsPerQuestion = 20;

    /** easy | medium | hard | mixed */
    public string $difficulty = 'mixed';

    /** ar | en | both */
    public string $hostScreenLocale = 'both';

    /** on | off: whether players can add a selfie (RoomSettings::showPhotos). */
    public string $photos = 'on';

    /** classic | vote (App\Enums\GameMode). Classic uses $categoryIds; Vote uses the three below and leaves $categoryIds as it was. */
    public string $mode = 'classic';

    /** Vote mode: the top-level category of the first batch (always in the pool). */
    public ?int $firstCategoryId = null;

    /** @var list<int> Vote mode: the top-level categories to vote between, the first category included */
    public array $poolIds = [];

    /** Vote mode: the players vote after every this many questions (VoteSettings::EVERY). */
    public int $voteEvery = 5;

    /** Vote mode: the first category. It joins the pool and stays in it while it is the first. */
    public function chooseFirst(int $id): void
    {
        if (! $this->categories->contains('id', $id)) {
            return;
        }

        $this->firstCategoryId = $id;
        $this->poolIds = array_values(array_unique([...$this->poolIds, $id]));
    }

    /** Vote mode: a category in or out of the pool. The first category is locked in. */
    public function togglePool(int $id): void
    {
        if ($id === $this->firstCategoryId || ! $this->categories->contains('id', $id)) {
            return;
        }

        $this->poolIds = in_array($id, $this->poolIds, true)
            ? array_values(array_diff($this->poolIds, [$id]))
            : [...$this->poolIds, $id];
    }

    /** A top-level category on or off as a whole. From a partial choice the first tap selects all of it. */
    public function toggleCategory(int $id): void
    {
        $category = $this->categories->firstWhere('id', $id);
        if ($category === null) {
            return;
        }

        $family = [$id, ...$category->children->pluck('id')->all()];
        $on = $category->children->isEmpty()
            ? in_array($id, $this->categoryIds, true)
            : CategorySelection::state($category, $this->categoryIds) === CategorySelection::ALL;

        $this->categoryIds = $on
            ? array_values(array_diff($this->categoryIds, $family))
            : [...array_diff($this->categoryIds, $family), $id];
    }

    /**
     * One subcategory on or off. With its parent chosen as a whole, switching a chip off leaves the siblings; choosing every
     * subcategory one by one is the same as choosing the parent.
     */
    public function toggleSubcategory(int $id): void
    {
        $parent = $this->categories->first(fn (Category $category) => $category->children->contains('id', $id));
        if ($parent === null) {
            return;
        }

        $siblings = $parent->children->pluck('id')->all();
        $ids = $this->categoryIds;

        if (in_array($parent->id, $ids, true)) {
            $ids = [...array_diff($ids, [$parent->id]), ...array_diff($siblings, [$id])];
        } elseif (in_array($id, $ids, true)) {
            $ids = array_diff($ids, [$id]);
        } else {
            $ids[] = $id;
            Analytics::dispatch($this, 'subcategory_selected', ['parent_category' => $parent->slug, 'subcategory' => $parent->children->firstWhere('id', $id)?->slug]);
            if (array_diff($siblings, $ids) === []) {
                $ids = [...array_diff($ids, $siblings), $parent->id];
            }
        }

        $this->categoryIds = array_values($ids);
    }

    public function toggleExpanded(int $id): void
    {
        $category = $this->categories->firstWhere('id', $id);

        $this->expandedId = $category === null || $category->children->isEmpty() || $this->expandedId === $id ? null : $id;
    }

    /** @return Collection<int, Category> the active top-level categories, each with its active subcategories loaded */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->active()->topLevel()
            ->with(['children' => fn ($children) => $children->active()])
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    #[Computed]
    public function settings(): RoomSettings
    {
        return new RoomSettings(
            // A Vote-mode room keeps its categories in the pool (room_category_pool), not here.
            categoryIds: $this->isVote() ? [] : CategorySelection::normalize($this->categoryIds),
            questionCount: $this->questionCount,
            secondsPerQuestion: $this->secondsPerQuestion,
            difficulty: Difficulty::tryFrom($this->difficulty),
            hostScreenLocale: HostScreenLocale::tryFrom($this->hostScreenLocale) ?? HostScreenLocale::Both,
            showPhotos: $this->photos !== 'off',
        );
    }

    public function isVote(): bool
    {
        return $this->mode === GameMode::Vote->value;
    }

    /** The Vote-mode setup as chosen so far, or null in Classic mode or before a first category is picked. */
    #[Computed]
    public function vote(): ?VoteSettings
    {
        return $this->isVote() && $this->firstCategoryId !== null
            ? new VoteSettings($this->firstCategoryId, array_values(array_unique([$this->firstCategoryId, ...$this->poolIds])), $this->voteEvery)
            : null;
    }

    /** The settings the questions are counted against: in Vote mode, the whole pool. */
    private function countedSettings(): RoomSettings
    {
        $settings = clone $this->settings;
        if ($this->isVote()) {
            $settings->categoryIds = $this->vote->poolIds ?? [];
        }

        return $settings;
    }

    /** How many playable questions the current choice has (Vote mode: the whole pool). */
    #[Computed]
    public function available(): int
    {
        return $this->countedSettings()->categoryIds === [] ? 0 : app(QuestionPool::class)->available($this->countedSettings());
    }

    /**
     * Vote mode: the pool categories that cannot supply a batch, id => questions they have.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function shortfalls(): array
    {
        return $this->vote ? app(CategoryVote::class)->shortfalls($this->settings, $this->vote) : [];
    }

    /** Vote mode: what is still wrong with the setup (the first problem only), or null when it can be created. */
    #[Computed]
    public function voteProblem(): ?string
    {
        if (! $this->isVote()) {
            return null;
        }
        if ($this->vote === null) {
            return __('Pick the first category.');
        }
        if (count($this->vote->poolIds) < VoteSettings::MIN_POOL) {
            return __('Pick at least :count categories to vote between.', ['count' => VoteSettings::MIN_POOL]);
        }
        if ($this->voteEvery >= $this->questionCount) {
            return __('Vote every :every questions needs a game longer than :every questions.', ['every' => $this->voteEvery]);
        }

        return null;
    }

    /** How many playable questions the host's usual group (the players of their latest game) has not seen yet; null when we cannot tell. */
    #[Computed]
    public function freshLeft(): ?int
    {
        $host = app(PlayerIdentity::class)->user();
        if ($host === null || $this->countedSettings()->categoryIds === []) {
            return null;
        }

        $picker = app(QuestionPicker::class);
        $group = $picker->lastGroup($host);

        return $group->isEmpty() ? null : $picker->freshFor($this->countedSettings(), $group);
    }

    #[Computed]
    public function canCreate(): bool
    {
        if ($this->isVote()) {
            return $this->voteProblem === null && $this->shortfalls === [] && $this->available >= $this->questionCount;
        }

        return $this->categoryIds !== [] && $this->available >= $this->questionCount;
    }

    public function create(RoomManager $rooms, PlayerIdentity $identity): mixed
    {
        $host = $identity->user() ?? abort(403);
        $this->validate();

        if ($this->isVote() && ($this->voteProblem !== null || $this->shortfalls !== [])) {
            $this->addError('poolIds', $this->voteProblem ?? __('Some categories do not have enough questions.'));

            return null;
        }

        try {
            $room = $rooms->create($host, $this->settings, $this->vote);
        } catch (NotEnoughQuestions $e) {
            $this->addError('questionCount', __('Only :available questions match this choice, but you picked :count.', ['available' => $e->available, 'count' => $e->needed]));

            return null;
        }

        Analytics::flash('room_created', [
            'difficulty' => $this->difficulty,
            'question_count' => $room->settings->questionCount,
            'categories_count' => count($this->vote->poolIds ?? $room->settings->categoryIds),
        ]);

        return $this->redirectRoute('host.lobby', ['room' => $room->code], navigate: false);
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        $topLevel = Rule::exists('categories', 'id')->where('is_active', true)->whereNull('parent_id');

        return [
            'mode' => [Rule::enum(GameMode::class)],
            ...($this->isVote() ? [
                'firstCategoryId' => ['required', 'integer', $topLevel],
                'poolIds' => ['array'],
                'poolIds.*' => ['integer', $topLevel],
                'voteEvery' => [Rule::in(VoteSettings::EVERY)],
            ] : [
                'categoryIds' => ['required', 'array', 'min:1'],
                'categoryIds.*' => [Rule::exists('categories', 'id')->where('is_active', true)],
            ]),
            'questionCount' => [Rule::in(RoomSettings::QUESTION_COUNTS)],
            'secondsPerQuestion' => [Rule::in(RoomSettings::SECONDS_PER_QUESTION)],
            'difficulty' => [Rule::in([...array_column(Difficulty::cases(), 'value'), 'mixed'])],
            'hostScreenLocale' => [Rule::enum(HostScreenLocale::class)],
            'photos' => [Rule::in(['on', 'off'])],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'categoryIds.required' => __('Pick at least one category.'),
            'categoryIds.min' => __('Pick at least one category.'),
            'firstCategoryId.required' => __('Pick the first category.'),
        ];
    }

    public function render(): View
    {
        return view('livewire.host.create-room')->title(__('Set up your game'));
    }
}
