<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\RedirectResponse;

/**
 * "Save your score" on the results screen: remember to come back to the results page, then log in or sign up. Logging in on this
 * device attaches the guest results to the account (ClaimGuestResults), and the results page then says "Saved to your profile".
 */
class SaveScoreController extends Controller
{
    public function __invoke(Room $room, string $action): RedirectResponse
    {
        session()->put('url.intended', route('play', $room->code));

        return redirect()->route($action);
    }
}
