<?php

namespace App\Game;

use App\Models\Room;
use App\Models\RoomQuestion;
use Carbon\CarbonInterface;

/**
 * How many points an answer is worth: a correct answer is 100 plus a speed bonus of 0-100, anything else 0, and the game's last question counts double
 * (base and bonus) when the host left that setting on. Only server times go in: when the question opened and when the answer was accepted.
 */
class ScoringService
{
    public const POINTS_PER_CORRECT_ANSWER = 100;

    public const SPEED_BONUS_MAX = 100;

    /** Reading the question and the network take a moment: an answer this quick still counts as full speed. */
    public const FULL_SPEED_MS = 1000;

    /** The bonus is rounded to the nearest multiple of this. */
    public const SPEED_BONUS_STEP = 5;

    public const FINAL_QUESTION_MULTIPLIER = 2;

    public function pointsFor(bool $correct, CarbonInterface $answeredAt, CarbonInterface $startedAt, CarbonInterface $endsAt, bool $finalQuestion = false): Points
    {
        $multiplier = $finalQuestion ? self::FINAL_QUESTION_MULTIPLIER : 1;

        return $correct
            ? new Points(self::POINTS_PER_CORRECT_ANSWER, $this->speedBonus($answeredAt, $startedAt, $endsAt), $multiplier)
            : new Points(0, 0, $multiplier);
    }

    /** This question counts double: it is the last of the game, in Classic and in Vote mode, and the host left "Double points on the last question" on. */
    public function isDoubled(Room $room, RoomQuestion $question): bool
    {
        return $room->settings->doubleFinal && $question->position >= $room->totalQuestions();
    }

    /** Full until FULL_SPEED_MS, then falling in a straight line to 0 at the deadline. An answer in the grace after the deadline gets none. */
    private function speedBonus(CarbonInterface $answeredAt, CarbonInterface $startedAt, CarbonInterface $endsAt): int
    {
        $elapsed = (int) $startedAt->diffInMilliseconds($answeredAt);
        $duration = (int) $startedAt->diffInMilliseconds($endsAt);

        if ($elapsed >= $duration) {
            return 0;
        }
        if ($elapsed <= self::FULL_SPEED_MS) {
            return self::SPEED_BONUS_MAX;
        }

        // One division of two whole numbers, so an exact half (52.5 points) is exact and rounds up the same way every time.
        $steps = round(self::SPEED_BONUS_MAX * ($duration - $elapsed) / (($duration - self::FULL_SPEED_MS) * self::SPEED_BONUS_STEP));

        return (int) $steps * self::SPEED_BONUS_STEP;
    }
}
