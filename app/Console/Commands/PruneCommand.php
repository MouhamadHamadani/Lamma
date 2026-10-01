<?php

namespace App\Console\Commands;

use App\Game\Housekeeping;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lamma:prune')]
#[Description('Close abandoned lobbies and forget the guest tokens that can no longer be claimed')]
class PruneCommand extends Command
{
    public function handle(Housekeeping $housekeeping): int
    {
        $lobbies = $housekeeping->closeAbandonedLobbies();
        $tokens = $housekeeping->clearExpiredGuestTokens();

        $this->info("Closed {$lobbies} abandoned lobbies, cleared {$tokens} expired guest tokens.");

        return self::SUCCESS;
    }
}
