<?php

namespace App\Console\Commands;

use App\Models\Establishment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeDemoAccounts extends Command
{
    protected $signature = 'datenest:purge-demo
        {--domain=datenest.test : Email domain sa mga demo account}
        {--force : Walay confirmation prompt}';

    protected $description = 'Delete demo customers ug owners (ug ilang listings, reservations, reviews). Ang admin dili gayod labtan.';

    public function handle(): int
    {
        $domain = ltrim((string) $this->option('domain'), '@');

        // Admin gi-exclude gayod
        $users = User::where('role', '!=', 'admin')->where('email', 'like', '%@'.$domain)->orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->info("No demo accounts found for @{$domain}.");
            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'Email', 'Role'], $users->map(fn ($u) => [$u->id, $u->name, $u->email, $u->role])->all());

        $ids = $users->pluck('id');
        $estIds = Establishment::whereIn('owner_id', $ids)->pluck('id');
        $reservations = Reservation::whereIn('customer_id', $ids)->orWhereIn('establishment_id', $estIds)->count();

        $this->warn("This will also delete {$estIds->count()} establishment(s) and {$reservations} reservation(s), plus their photos and reviews.");

        if (! $this->option('force') && ! $this->confirm('Delete these accounts? This cannot be undone.')) {
            $this->line('Cancelled. Nothing was deleted.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($users) {
            foreach ($users as $user) {
                $user->tokens()->delete();
                $user->delete(); // cascade sa database
            }
        });

        $this->info("Deleted {$users->count()} demo account(s). Admin accounts were not touched.");
        return self::SUCCESS;
    }
}