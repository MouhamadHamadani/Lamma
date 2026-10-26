<?php

namespace App\Console\Commands;

use App\Game\Housekeeping;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('lamma:prune')]
#[Description('Close abandoned lobbies and forget the guest tokens and sightings that can no longer be claimed')]
class PruneCommand extends Command
{
    public function handle(Housekeeping $housekeeping): int
    {
        $lobbies = $housekeeping->closeAbandonedLobbies();
        $tokens = $housekeeping->clearExpiredGuestTokens();
        $sightings = $housekeeping->forgetGuestSightings();

        // The scheduler's heartbeat: `lamma:doctor` reads it to know the cron line is alive (see config/lamma.php).
        Cache::forever(config('lamma.heartbeat_key'), now()->timestamp);

        $this->info("Closed {$lobbies} abandoned lobbies, cleared {$tokens} expired guest tokens.");
        $this->info("Forgot {$sightings} guest sightings.");

        return self::SUCCESS;
    }
}
