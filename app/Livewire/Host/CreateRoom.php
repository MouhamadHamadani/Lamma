<?php

namespace App\Livewire\Host;

use App\Enums\Difficulty;
use App\Enums\HostScreenLocale;
use App\Game\CategorySelection;
use App\Game\NotEnoughQuestions;
use App\Game\PlayerIdentity;
use App\Game\QuestionPicker;
use App\Game\QuestionPool;
use App\Game\RoomManager;
use App\Game\RoomSettings;
use App\Models\Category;
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
            categoryIds: CategorySelection::normalize($this->categoryIds),
            questionCount: $this->questionCount,
            secondsPerQuestion: $this->secondsPerQuestion,
            difficulty: Difficulty::tryFrom($this->difficulty),
            hostScreenLocale: HostScreenLocale::tryFrom($this->hostScreenLocale) ?? HostScreenLocale::Both,
        );
    }

    /** How many playable questions the current choice has. */
    #[Computed]
    public function available(): int
    {
        return $this->categoryIds === [] ? 0 : app(QuestionPool::class)->available($this->settings);
    }

    /** How many playable questions the host's usual group (the players of their latest game) has not seen yet; null when we cannot tell. */
    #[Computed]
    public function freshLeft(): ?int
    {
        $host = app(PlayerIdentity::class)->user();
        if ($host === null || $this->categoryIds === []) {
            return null;
        }

        $picker = app(QuestionPicker::class);
        $group = $picker->lastGroup($host);

        return $group->isEmpty() ? null : $picker->freshFor($this->settings, $group);
    }

    #[Computed]
    public function canCreate(): bool
    {
        return $this->categoryIds !== [] && $this->available >= $this->questionCount;
    }

    public function create(RoomManager $rooms, PlayerIdentity $identity): mixed
    {
        $host = $identity->user() ?? abort(403);
        $this->validate();

        try {
            $room = $rooms->create($host, $this->settings);
        } catch (NotEnoughQuestions $e) {
            $this->addError('questionCount', __('Only :available questions match this choice, but you picked :count.', ['available' => $e->available, 'count' => $e->needed]));

            return null;
        }

        return $this->redirectRoute('host.lobby', ['room' => $room->code], navigate: false);
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'categoryIds' => ['required', 'array', 'min:1'],
            'categoryIds.*' => [Rule::exists('categories', 'id')->where('is_active', true)],
            'questionCount' => [Rule::in(RoomSettings::QUESTION_COUNTS)],
            'secondsPerQuestion' => [Rule::in(RoomSettings::SECONDS_PER_QUESTION)],
            'difficulty' => [Rule::in([...array_column(Difficulty::cases(), 'value'), 'mixed'])],
            'hostScreenLocale' => [Rule::enum(HostScreenLocale::class)],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'categoryIds.required' => __('Pick at least one category.'),
            'categoryIds.min' => __('Pick at least one category.'),
        ];
    }

    public function render(): View
    {
        return view('livewire.host.create-room')->title(__('Set up your game'));
    }
}
