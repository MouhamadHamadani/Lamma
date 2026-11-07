<?php

namespace App\Game;

use App\Enums\QuestionType;
use App\Models\Category;
use App\Models\PlayerAnswer;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use Illuminate\Support\Collection;

/** A snapshot of the game on screen (see GameView). Read-only; the correct option is null until the reveal. */
final class GameState
{
    /**
     * @param  Collection<int, QuestionOption>  $options  in display order (A, B, C, D)
     * @param  Collection<int, RoomPlayer>  $players  in join order
     * @param  Collection<int, PlayerAnswer>  $answers  by player id
     * @param  list<array{rank: int, player: array{id: int, nickname: string, locale: string, avatar_url?: string|null}, total: int, gained: int, rank_change: int}>  $ranking  empty until revealed
     * @param  array<int, int>  $previousPositions  player id => 0-based position before this round's points
     * @param  array<int, int>  $streaks  player id => right answers in a row up to this question; empty until revealed
     * @param  bool  $doubled  this question counts double (ScoringService::isDoubled): the screens show the x2 chip
     * @param  float|null  $correctNumber  closest number: the correct value; null until the reveal (and for the other types)
     * @param  list<array{player: RoomPlayer, value: float, distance: float, points: int, closest: bool}>  $guesses  closest number: every guess, nearest first; empty until the reveal
     */
    public function __construct(
        public readonly RoomQuestion $roomQuestion,
        public readonly Question $question,
        public readonly Category $category,
        public readonly Collection $options,
        public readonly Collection $players,
        public readonly Collection $answers,
        public readonly int $total,
        public readonly int $answered,
        public readonly int $expected,
        public readonly ?int $correctOptionId,
        public readonly array $ranking,
        public readonly array $previousPositions,
        public readonly bool $finished,
        public readonly array $streaks = [],
        public readonly bool $doubled = false,
        public readonly ?float $correctNumber = null,
        public readonly array $guesses = [],
    ) {}

    public function type(): QuestionType
    {
        return $this->question->type;
    }

    /** Closest number: answered by typing, so there are no tiles. */
    public function isNumber(): bool
    {
        return $this->question->type->takesNumber();
    }

    /** The fun fact in one language, once the answer is out (never before: it could give the answer away); null when the question has none. */
    public function fact(string $locale): ?string
    {
        if (! $this->isRevealed()) {
            return null;
        }

        return filled($fact = $this->question->getTranslation('explanation', $locale, false)) ? $fact : null;
    }

    public function position(): int
    {
        return $this->roomQuestion->position;
    }

    public function isRevealed(): bool
    {
        return $this->roomQuestion->revealed_at !== null;
    }

    /** The game ran to its end (the last question was revealed and the room finished), as opposed to a room closed part-way. */
    public function completed(): bool
    {
        return $this->finished && $this->isLast() && $this->isRevealed();
    }

    public function isLast(): bool
    {
        return $this->position() >= $this->total;
    }

    /** 0-based display index (A=0 ... D=3) of an option, or null. */
    public function indexOf(?int $optionId): ?int
    {
        $index = $this->options->search(fn (QuestionOption $option) => $option->id === $optionId);

        return $index === false ? null : (int) $index;
    }

    public function answerOf(RoomPlayer $player): ?PlayerAnswer
    {
        return $this->answers->get($player->id);
    }

    /**
     * Who picked this option (players, in join order). Only meaningful after the reveal.
     *
     * @return Collection<int, RoomPlayer>
     */
    public function pickedBy(int $optionId): Collection
    {
        return $this->players->filter(fn (RoomPlayer $player) => $this->answers->get($player->id)?->question_option_id === $optionId)->values();
    }

    /**
     * The fun fact as a screen shows it: the line in the screen's main language (the other language's when only that one was written) and, on the big screen in
     * Both, the Arabic line under it. Null before the reveal and when the question has no fact.
     *
     * @return array{text: string, lang: string, alt: string|null}|null
     */
    public function factFor(string $main, bool $both = false): ?array
    {
        $other = $main === 'ar' ? 'en' : 'ar';
        $text = $this->fact($main);
        $lang = $main;
        if ($text === null && ($text = $this->fact($other)) !== null) {
            $lang = $other;
        }
        if ($text === null) {
            return null;
        }

        return ['text' => $text, 'lang' => $lang, 'alt' => $both && $lang === 'en' ? $this->fact('ar') : null];
    }

    /** Right answers in a row for this player, counting this question (0 until the reveal). */
    public function streakOf(RoomPlayer $player): int
    {
        return $this->streaks[$player->id] ?? 0;
    }

    public function correctCount(): int
    {
        return $this->answers->where('is_correct', true)->count();
    }

    /** @return array{rank: int, player: array{id: int, nickname: string, locale: string, avatar_url?: string|null}, total: int, gained: int, rank_change: int}|null */
    public function rankingRow(RoomPlayer $player): ?array
    {
        return collect($this->ranking)->firstWhere('player.id', $player->id);
    }
}
