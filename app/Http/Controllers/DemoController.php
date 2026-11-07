<?php

namespace App\Http\Controllers;

use App\Game\DemoLimitReached;
use App\Game\DemoRoom;
use App\Game\NotEnoughQuestions;
use App\Support\Analytics;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The no-signup demo: a page that explains it, and the button that starts a demo room for this browser (App\Game\DemoRoom). */
class DemoController extends Controller
{
    public function show(): View
    {
        return view('demo');
    }

    public function start(Request $request, DemoRoom $demo): RedirectResponse
    {
        try {
            $room = $demo->start((string) $request->ip());
        } catch (DemoLimitReached $e) {
            return back()->withErrors(['demo' => __('You have started a lot of demos. Please try again in :minutes minutes.', ['minutes' => max(1, (int) ceil($e->retryAfter / 60))])]);
        } catch (NotEnoughQuestions) {
            return back()->withErrors(['demo' => __('The demo is not ready right now. Please try again later.')]);
        }

        // Reported by the host screen this redirect leads to (no names, codes or accounts: App\Support\Analytics).
        Analytics::flash('demo_started');

        return redirect()->route('host.lobby', ['room' => $room->code]);
    }
}
