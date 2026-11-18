<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Mga account para sa demo (walay labot sa datos nga naa na):
 *  - 25 ka owner account nga WALA pay establishment (ikaw ang mo-list)
 *
 * Idempotent: kung naa na ang email, dili na ma-doble ug dili usbon ang password.
 * Run: php artisan db:seed --class=DemoAccountsSeeder
 *
 * Password sa tanan: "password"  (usba kung ihatag na sa tinuod nga tawo)
 */
class DemoAccountsSeeder extends Seeder
{
    public const OWNER_COUNT = 25;

    public function run(): void
    {
        $hash = Hash::make('password');

        $make = function (string $first, string $last, string $email, string $role) use ($hash) {
            return User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => trim("$first $last"),
                    'first_name' => $first,
                    'last_name' => $last,
                    'password' => $hash,
                    'role' => $role,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );
        };

        // 25 ka owner (wala pay establishment, business permit, o logo: i-upload nila/nimo inig register sa listing)
        for ($i = 1; $i <= self::OWNER_COUNT; $i++) {
            $make('Owner', (string) $i, "owner{$i}@datenest.test", 'establishment_admin');
        }

        $this->command?->info('Owners: owner1..owner'.self::OWNER_COUNT.'@datenest.test | Password: password');
    }
}