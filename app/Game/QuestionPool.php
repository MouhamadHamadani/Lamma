<?php

namespace App\Game;

use App\Models\Category;
use App\Models\Question;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which questions a game may use. Every game uses only questions fully translated in BOTH Arabic and English,
 * whatever the host-screen language is, because players pick their own language.
 */
class QuestionPool
{
    /** @var list<string> */
    public const LOCALES = ['ar', 'en'];

    /** @return Builder<Question> Active, fully translated questions in active chosen categories (and the difficulty, unless Mixed). */
    public function query(RoomSettings $settings): Builder
    {
        return Question::query()
            ->active()
            ->translatedIn(self::LOCALES)
            ->whereIn('category_id', Category::query()->active()->whereIn('id', $settings->categoryIds)->select('id'))
            ->when($settings->difficulty, fn (Builder $query, $difficulty) => $query->where('difficulty', $difficulty));
    }

    public function available(RoomSettings $settings): int
    {
        return $this->query($settings)->count();
    }

    public function isEnough(RoomSettings $settings): bool
    {
        return $this->available($settings) >= $settings->questionCount;
    }
}
