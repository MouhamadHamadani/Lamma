<?php

namespace App\Game;

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
     * @param  list<array{rank: int, player: array{id: int, nickname: string, locale: string}, total: int, gained: int}>  $ranking  empty until revealed
     * @param  array<int, int>  $previousPositions  player id => 0-based position before this round's points
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
    ) {}

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

    public function correctCount(): int
    {
        return $this->answers->where('is_correct', true)->count();
    }

    /** @return array{rank: int, player: array{id: int, nickname: string, locale: string}, total: int, gained: int}|null */
    public function rankingRow(RoomPlayer $player): ?array
    {
        return collect($this->ranking)->firstWhere('player.id', $player->id);
    }
}
