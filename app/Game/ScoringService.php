<?php

namespace App\Game;

use App\Models\PlayerAnswer;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomQuestion;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * How many points an answer is worth: a correct answer is 100 plus a speed bonus of 0-100, anything else 0, and the game's last question counts double
 * (base and bonus) when the host left that setting on. Only server times go in: when the question opened and when the answer was accepted.
 *
 * A closest-number question scores differently (closestPoints): up to 100 for how near the guess is to the answer, falling in a straight line to 0 at the
 * tolerance, no speed bonus, and +50 for the closest guess (awardClosestBonus, at the reveal). The last question still counts double.
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

    /** Closest number: the extra for the closest guess (ties share it). */
    public const CLOSEST_BONUS = 50;

    public function pointsFor(bool $correct, CarbonInterface $answeredAt, CarbonInterface $startedAt, CarbonInterface $endsAt, bool $finalQuestion = false): Points
    {
        $multiplier = $finalQuestion ? self::FINAL_QUESTION_MULTIPLIER : 1;

        return $correct
            ? new Points(self::POINTS_PER_CORRECT_ANSWER, $this->speedBonus($answeredAt, $startedAt, $endsAt), $multiplier)
            : new Points(0, 0, $multiplier);
    }

    /**
     * A closest-number guess: 100 x (1 - distance / tolerance) rounded to a whole point, never below 0. The answer itself is 100, a guess a quarter of the tolerance
     * away is 75, one at the tolerance or beyond is 0. A guess that scores nothing is "wrong". No speed bonus: thinking about a number takes time.
     */
    public function closestPoints(float $guess, float $answer, float $tolerance, bool $finalQuestion = false): Points
    {
        $multiplier = $finalQuestion ? self::FINAL_QUESTION_MULTIPLIER : 1;
        $tolerance = max($tolerance, 0.0001);
        $distance = $this->distance($guess, $answer);

        // One division of whole-ish numbers, so an exact half (87.5) is exact and rounds up the same way every time.
        $base = $distance >= $tolerance ? 0 : (int) round(self::POINTS_PER_CORRECT_ANSWER * ($tolerance - $distance) / $tolerance);

        return new Points($base, 0, $multiplier);
    }

    /** How far a guess is from the answer, to the precision we keep (four decimals), so equal distances compare equal. */
    public function distance(float $guess, float $answer): float
    {
        return round(abs($guess - $answer), 4);
    }

    /**
     * The closest guesses: among the answers that scored, those at the smallest distance from the answer. Ties share. Nobody scored: nobody.
     *
     * @param  Collection<int, PlayerAnswer>  $answers
     * @return list<int> answer ids
     */
    public function closestWinners(Collection $answers, float $answer): array
    {
        $scored = $answers->filter(fn (PlayerAnswer $given) => $given->is_correct && $given->number_value !== null);
        if ($scored->isEmpty()) {
            return [];
        }

        $nearest = $scored->min(fn (PlayerAnswer $given) => $this->distance((float) $given->number_value, $answer));

        return array_values($scored->filter(fn (PlayerAnswer $given) => $this->distance((float) $given->number_value, $answer) === $nearest)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * At the reveal of a closest-number question: give the closest guess (or guesses) the extra, on top of what they already scored, with the
     * question's multiplier. Returns the ids of the answers that got it. Called once, inside the reveal's transaction.
     *
     * @return list<int>
     */
    public function awardClosestBonus(RoomQuestion $roomQuestion, Question $question): array
    {
        $answers = $roomQuestion->answers()->get();
        $winners = $this->closestWinners($answers, (float) $question->answer_number);

        foreach ($answers->whereIn('id', $winners) as $answer) {
            $points = new Points((int) $answer->base_points, (int) $answer->speed_bonus, (int) ($answer->multiplier ?? 1), self::CLOSEST_BONUS);
            $answer->update(['closest_bonus' => self::CLOSEST_BONUS, 'points' => $points->total]);
        }

        return $winners;
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
