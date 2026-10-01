<?php

namespace App\Livewire\Host;

use App\Enums\Difficulty;
use App\Enums\HostScreenLocale;
use App\Game\NotEnoughQuestions;
use App\Game\PlayerIdentity;
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
 * @property-read Collection<int, Category> $categories
 * @property-read RoomSettings $settings
 * @property-read int $available
 * @property-read bool $canCreate
 */
#[Layout('layouts::lamma')]
class CreateRoom extends Component
{
    /** @var list<int> */
    public array $categoryIds = [];

    public int $questionCount = 10;

    public int $secondsPerQuestion = 20;

    /** easy | medium | hard | mixed */
    public string $difficulty = 'mixed';

    /** ar | en | both */
    public string $hostScreenLocale = 'both';

    public function toggleCategory(int $id): void
    {
        if (! $this->categories->contains('id', $id)) {
            return;
        }

        $this->categoryIds = in_array($id, $this->categoryIds, true)
            ? array_values(array_diff($this->categoryIds, [$id]))
            : [...$this->categoryIds, $id];
    }

    /** @return Collection<int, Category> */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->active()->orderBy('sort_order')->orderBy('id')->get();
    }

    #[Computed]
    public function settings(): RoomSettings
    {
        return new RoomSettings(
            categoryIds: array_values(array_unique(array_map('intval', $this->categoryIds))),
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
