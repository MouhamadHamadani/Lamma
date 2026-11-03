<?php

namespace App\Events;

use App\Enums\VoteOutcome;
use App\Game\CategoryVote;
use App\Models\CategoryVoteRound;
use App\Models\Room;

/**
 * The vote is over: the winning category, the votes per category, who voted for each (for the host screen's mini avatars, the way QuestionRevealed
 * carries who picked each answer), and whether a tie or nobody voting made it a random pick. The next question follows after the result beat.
 */
class VoteResult extends RoomEvent
{
    /** @var array<string, mixed> */
    public readonly array $result;

    public function __construct(Room $room, CategoryVoteRound $round)
    {
        parent::__construct($room->code);

        $votes = $round->votes()->with('roomPlayer')->get();
        $photos = $room->settings->showPhotos;

        $this->result = [
            'round' => $round->round,
            'winner_id' => $round->winner_category_id,
            'counts' => collect(app(CategoryVote::class)->counts($round))->map(fn (int $count, int $id) => ['category_id' => $id, 'votes' => $count])->values()->all(),
            'voters' => collect($round->option_ids)->map(fn (int $id) => [
                'category_id' => $id,
                'players' => $votes->where('category_id', $id)->sortBy('id')->map(fn ($vote) => [
                    'id' => $vote->roomPlayer->id,
                    'nickname' => $vote->roomPlayer->nickname,
                    ...($photos ? ['avatar_url' => $vote->roomPlayer->avatarUrl()] : []),
                ])->values()->all(),
            ])->all(),
            'was_tie' => $round->outcome === VoteOutcome::Tie,
            'no_votes' => $round->outcome === VoteOutcome::NoVotes,
            'closed_at' => self::iso($round->closed_at),
        ];
    }

    protected function payload(): array
    {
        return $this->result;
    }
}
