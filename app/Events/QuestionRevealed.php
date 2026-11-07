<?php

namespace App\Events;

use App\Game\Scoreboard;
use App\Game\ScoringService;
use App\Models\PlayerAnswer;
use App\Models\Room;
use App\Models\RoomQuestion;
use Illuminate\Support\Collection;

/** The question is over: the correct option, who picked each option, and the points each player gained. */
class QuestionRevealed extends RoomEvent
{
    /** @var array<string, mixed> */
    public readonly array $reveal;

    public function __construct(Room $room, RoomQuestion $roomQuestion)
    {
        parent::__construct($room->code);

        $question = $roomQuestion->question()->with('options')->firstOrFail();
        $answers = $roomQuestion->answers()->with('roomPlayer')->get();
        $options = collect($roomQuestion->option_order)->map(fn (int $id) => $question->options->firstWhere('id', $id))->filter();
        $gained = $answers->pluck('points', 'room_player_id');
        $streaks = app(Scoreboard::class)->streaks($room, $roomQuestion);

        $this->reveal = [
            'position' => $roomQuestion->position,
            'type' => $question->type->value,
            'correct_option_id' => $question->options->firstWhere('is_correct', true)?->id,
            // Closest number: the right value, and every guess with how far it was and what it scored, nearest first. Null / empty for the other types.
            'correct_number' => $question->type->takesNumber() ? (float) $question->answer_number : null,
            'guesses' => $question->type->takesNumber() ? $this->guesses($room, $answers, (float) $question->answer_number) : [],
            // The optional fun fact, {ar, en}: out only now, because it can give the answer away.
            'explanation' => filled($question->getTranslations('explanation')) ? self::translations($question, 'explanation') : null,
            'picks' => $options->map(fn ($option) => [
                'option_id' => $option->id,
                'players' => $answers->where('question_option_id', $option->id)
                    ->map(fn ($answer) => [
                        'id' => $answer->roomPlayer->id,
                        'nickname' => $answer->roomPlayer->nickname,
                        ...($room->settings->showPhotos ? ['avatar_url' => $answer->roomPlayer->avatarUrl()] : []),
                    ])->values()->all(),
            ])->values()->all(),
            // points: the total this answer scored. streak: right answers in a row so far (display only: it scores nothing)
            'points' => $room->players()->orderBy('id')->pluck('id')->map(fn (int $id) => ['player_id' => $id, 'points' => (int) ($gained[$id] ?? 0), 'streak' => $streaks[$id] ?? 0])->all(),
            'revealed_at' => self::iso($roomQuestion->revealed_at),
        ];
    }

    /**
     * @param  Collection<int, PlayerAnswer>  $answers
     * @return list<array{player: array{id: int, nickname: string, avatar_url?: string|null}, value: float, distance: float, points: int, closest: bool}>
     */
    private function guesses(Room $room, Collection $answers, float $correct): array
    {
        $scoring = app(ScoringService::class);

        $guesses = $answers->whereNotNull('number_value')->sortBy('answered_at')
            ->map(fn (PlayerAnswer $answer) => [
                'player' => [
                    'id' => $answer->roomPlayer->id,
                    'nickname' => $answer->roomPlayer->nickname,
                    ...($room->settings->showPhotos ? ['avatar_url' => $answer->roomPlayer->avatarUrl()] : []),
                ],
                'value' => (float) $answer->number_value,
                'distance' => $scoring->distance((float) $answer->number_value, $correct),
                'points' => $answer->points,
                'closest' => ($answer->closest_bonus ?? 0) > 0,
            ]);

        return array_values($guesses->sortBy('distance')->all());
    }

    protected function payload(): array
    {
        return $this->reveal;
    }
}
