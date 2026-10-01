<?php

namespace App\Livewire\Player;

use App\Game\PlayerStats;
use App\Models\Category;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /me/games: the games the logged-in user has played and saved (rows tied to their account; a guest's score is not saved until it is
 * claimed), with totals on top: games played, wins and the share of questions answered correctly.
 */
#[Layout('layouts::lamma')]
class MyGames extends Component
{
    use WithPagination;

    public function render(PlayerStats $stats): View
    {
        $user = Auth::guard('web')->user() ?? abort(403);
        $games = $stats->history($user);

        $categories = Category::query()->whereIn('id', $games->getCollection()->flatMap(fn ($game) => $game->room->settings->categoryIds)->unique()->all())->get()->keyBy('id');

        return view('livewire.player.my-games', [
            'user' => $user,
            'totals' => $stats->totals($user),
            'games' => $games,
            'categories' => $categories,
        ])->title(__('My games'));
    }
}
