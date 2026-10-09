<?php

namespace Database\Seeders\Concerns;

use Illuminate\Console\Command;

/** A line for whoever ran `db:seed`. Seeders called from code (the tests) have no console, and just stay quiet. */
trait ReportsToConsole
{
    /** @var Command|null */
    protected $command;

    private function say(string $line, bool $warning = false): void
    {
        if ($this->command === null) {
            return;
        }

        $warning ? $this->command->warn($line) : $this->command->info($line);
    }
}
