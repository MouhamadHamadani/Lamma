<?php

namespace App\Listeners;

use App\Game\DemoStats;
use App\Game\PlayerIdentity;
use Illuminate\Auth\Events\Registered;

/**
 * Demo to signup, for the dashboard: someone registers on a browser that still holds a demo host cookie (a day) hosted a demo first. It counts people, not
 * accounts: nothing is stored about who they are (App\Game\DemoStats).
 */
class AttributeDemoSignup
{
    public function __construct(private readonly PlayerIdentity $identity, private readonly DemoStats $stats) {}

    public function handle(Registered $event): void
    {
        if ($this->identity->demoHostToken() !== null) {
            $this->stats->converted();
        }
    }
}
