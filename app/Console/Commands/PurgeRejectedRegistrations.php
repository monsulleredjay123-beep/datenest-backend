<?php

namespace App\Console\Commands;

use App\Models\Establishment;
use Illuminate\Console\Command;

class PurgeRejectedRegistrations extends Command
{
    protected $signature = 'datenest:purge-rejected';

    protected $description = 'Delete rejected establishment registrations after the retention period (default: 2 days)';

    public function handle(): int
    {
        $count = Establishment::purgeRejected();
        $this->info("Deleted {$count} rejected registration(s).");

        return self::SUCCESS;
    }
}