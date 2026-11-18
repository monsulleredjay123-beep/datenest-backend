<?php

namespace App\Console\Commands;

use App\Models\Establishment;
use Illuminate\Console\Command;

class DeactivateExpiredListings extends Command
{
    protected $signature = 'datenest:deactivate-expired';

    protected $description = 'I-deactivate ang mga establishment nga na-expire na ang advertising contract.';

    public function handle(): int
    {
        $count = Establishment::deactivateExpired();

        $this->info("Deactivated {$count} expired establishment(s).");

        return self::SUCCESS;
    }
}