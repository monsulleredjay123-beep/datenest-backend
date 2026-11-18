<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Establishment;
use App\Models\Preference;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $hash = Hash::make('password'); // password sa tanan: "password"

        $make = fn (string $name, string $email, string $role) => User::create([
            'name' => $name,
            'email' => $email,
            'password' => $hash,
            'role' => $role,
        ]);

        // ---------- USERS (parehas sa order sa Angular mock: id 1..16) ----------
        $make('System Admin', 'admin@datenest.test', 'admin');                 // 1
        $make('Demo Customer', 'customer@datenest.test', 'customer');          // 2
        for ($i = 1; $i <= 10; $i++) {                                         // 3..12
            $make("Owner $i", "owner$i@datenest.test", 'establishment_admin');
        }
        $make('Maria Santos', 'maria@datenest.test', 'customer');              // 13
        $make('John Reyes', 'john@datenest.test', 'customer');                 // 14
        $make('Owner 11', 'owner11@datenest.test', 'establishment_admin');     // 15
        $make('Owner 12', 'owner12@datenest.test', 'establishment_admin');     // 16

        $owners = User::where('role', 'establishment_admin')->orderBy('id')->get();

        // ---------- PREFERENCES / AMENITIES ----------
        $preferences = collect([
            'Romantic', 'Quiet', 'Outdoor', 'Scenic View',
            'Live Music', 'Pet Friendly', 'Private', 'Budget Friendly',
        ])->mapWithKeys(fn ($n) => [$n => Preference::create(['name' => $n])->id]);

        $amenities = collect([
            'Wi-Fi', 'Parking', 'Air Conditioning', 'Private Room', 'Restroom', 'Garden',
        ])->mapWithKeys(fn ($n) => [$n => Amenity::create(['name' => $n])->id]);

        // ---------- ESTABLISHMENTS ----------
        // SAMPLE DATA ra ni. Ilisi sa tinuod nga establishments sa Tagum kung naa na.
        // [name, category, description, address, lat, lng, open, close, rating, price, preferences, amenities]
        $places = [
            ['Moonlit Garden Café', 'cafe', 'A cozy garden café with soft lights, perfect for evening dates.', 'Rizal St, Tagum City', 7.4478, 125.8078, '08:00', '22:00', 4.6, 350, ['Romantic', 'Quiet', 'Outdoor'], ['Wi-Fi', 'Garden', 'Parking']],
            ['Sunset Terrace Restaurant', 'restaurant', 'Rooftop dining with a view of the city at sunset.', 'Quezon St, Tagum City', 7.4501, 125.8102, '10:00', '22:00', 4.8, 600, ['Romantic', 'Scenic View', 'Live Music'], ['Air Conditioning', 'Parking', 'Restroom']],
            ['Cozy Corner Café', 'cafe', 'Small, quiet café with affordable coffee and pastries.', 'Mabini St, Tagum City', 7.4455, 125.806, '07:00', '21:00', 4.2, 250, ['Quiet', 'Budget Friendly', 'Pet Friendly'], ['Wi-Fi', 'Air Conditioning']],
            ['Harbor Grill', 'restaurant', 'Grilled seafood and steaks in a relaxed riverside setting.', 'Apokon Rd, Tagum City', 7.442, 125.815, '11:00', '22:00', 4.4, 800, ['Outdoor', 'Scenic View'], ['Parking', 'Restroom', 'Garden']],
            ['Lantern Inn', 'inn', 'Quiet inn with clean private rooms and warm lighting.', 'National Hwy, Tagum City', 7.453, 125.804, '00:00', '23:59', 4.1, 1200, ['Private', 'Quiet', 'Romantic'], ['Private Room', 'Air Conditioning', 'Wi-Fi']],
            ['Palm Lodge', 'lodge', 'Affordable lodge close to the city center.', 'Pioneer Ave, Tagum City', 7.439, 125.811, '00:00', '23:59', 3.9, 900, ['Budget Friendly', 'Private'], ['Parking', 'Air Conditioning']],
            ['Rosewood Bistro', 'restaurant', 'Intimate bistro known for pasta and desserts.', 'Magugpo Poblacion, Tagum City', 7.4488, 125.8091, '10:00', '21:00', 4.5, null, ['Romantic', 'Quiet'], ['Air Conditioning', 'Wi-Fi']],
            ['Kape Tagum', 'cafe', 'Local coffee shop with live acoustic nights.', 'Magugpo East, Tagum City', 7.4465, 125.8125, '07:00', '22:00', 4.3, 180, ['Live Music', 'Budget Friendly'], ['Wi-Fi', 'Restroom']],
            ['Riverside Lodge', 'lodge', 'Peaceful lodge by the river with garden views.', 'Visayan Village, Tagum City', 7.456, 125.8, '00:00', '23:59', 4.4, 1500, ['Scenic View', 'Outdoor', 'Private'], ['Garden', 'Parking', 'Private Room']],
            ['Starlight Inn', 'inn', 'Premium inn with spacious rooms and room service.', 'Apokon, Tagum City', 7.441, 125.818, '00:00', '23:59', 4.7, 2000, ['Romantic', 'Private', 'Scenic View'], ['Private Room', 'Air Conditioning', 'Wi-Fi', 'Parking']],
            ['Riverside Sunset Park', 'destination', 'Open park by the river, popular for sunset walks and picnics.', 'Tagum City', 7.447, 125.811, '05:00', '21:00', 4.3, 50, ['Outdoor', 'Scenic View', 'Budget Friendly'], ['Parking', 'Restroom', 'Garden']],
            ['Starlight Movie House', 'destination', 'Cozy cinema for movie dates.', 'Tagum City', 7.4485, 125.8085, '10:00', '22:00', 4.1, 250, ['Quiet', 'Romantic', 'Private'], ['Air Conditioning', 'Restroom', 'Parking']],
        ];

        foreach ($places as $i => [$name, $category, $description, $address, $lat, $lng, $open, $close, $rating, $price, $prefs, $amen]) {
            // Reservation fee: walay rate = walay online payment
            $fee = $price === null ? null : match ($category) {
                'inn', 'lodge' => 300,
                'destination' => 50,
                default => 100,
            };

            $e = Establishment::create([
                'owner_id' => $owners[$i]->id, // establishment N -> owner N
                'name' => $name,
                'category' => $category,
                'description' => $description,
                'address' => $address,
                'lat' => $lat,
                'lng' => $lng,
                'opening_time' => $open,
                'closing_time' => $close,
                'price' => $price,
                'reservation_fee' => $fee,
                'is_active' => true,
                'avg_rating' => $rating,
            ]);

            $e->preferences()->sync(collect($prefs)->map(fn ($p) => $preferences[$p])->all());
            $e->amenities()->sync(collect($amen)->map(fn ($a) => $amenities[$a])->all());
            $e->photos()->create(['url' => 'https://picsum.photos/seed/datenest'.($i + 1).'/800/500']);
        }

        // ---------- RESERVATIONS (sample, para ma-test ang tanang status) ----------
        $reserve = function (int $customerId, int $estId, string $date, string $time, int $guests, string $notes, string $status, string $created, bool $paid = false) {
            $est = Establishment::find($estId);
            $fee = (float) ($est->reservation_fee ?? 0);
            $isPaid = $paid && $fee > 0;

            Reservation::forceCreate([
                'customer_id' => $customerId,
                'establishment_id' => $estId,
                'date' => $date,
                'time' => $time,
                'guests' => $guests,
                'notes' => $notes ?: null,
                'status' => $status,
                'status_updated_at' => $created,
                'amount_due' => $fee,
                'payment_status' => $fee > 0 ? ($isPaid ? 'Paid' : 'Unpaid') : 'Not Required',
                'payment_method' => $isPaid ? 'GCash' : null,
                'payment_ref' => $isPaid ? 'DN-DEMO'.rand(1000, 9999) : null,
                'paid_at' => $isPaid ? $created : null,
                'created_at' => $created,
                'updated_at' => $created,
            ]);
        };

        // Demo Customer (id 2): tanang status
        $reserve(2, 1, '2026-10-10', '18:30', 2, 'Anniversary dinner', 'Completed', '2026-10-05 10:00:00', true);
        $reserve(2, 2, '2026-10-25', '19:00', 2, 'Window seat please', 'Approved', '2026-10-15 09:00:00');
        $reserve(2, 5, '2026-11-02', '20:00', 2, '', 'Pending', '2026-10-17 14:00:00');
        $reserve(2, 4, '2026-10-12', '19:30', 4, '', 'Rejected', '2026-10-08 08:00:00');
        // Para sa owner1 (Moonlit Garden Café)
        $reserve(13, 1, '2026-10-22', '17:00', 2, 'Birthday date', 'Pending', '2026-10-17 16:00:00');
        $reserve(14, 1, '2026-10-20', '19:00', 2, '', 'Approved', '2026-10-14 11:00:00', true);

        // ---------- SAMPLE REVIEWS ----------
        $reviewers = [];
        foreach (['Ana R.', 'Mark S.', 'Liza P.', 'Jun D.', 'Grace T.', 'Paul M.', 'Kim L.', 'Rhea C.', 'Dan V.'] as $n => $name) {
            $reviewers[$name] = User::create([
                'name' => $name,
                'email' => 'reviewer'.($n + 1).'@datenest.test',
                'password' => $hash,
                'role' => 'customer',
            ])->id;
        }

        $reviews = [
            [1, 'Ana R.', 5, 'Super romantic lights and the coffee was great!', '2026-09-12'],
            [1, 'Mark S.', 4, 'Quiet and cozy. A bit crowded on weekends.', '2026-09-28'],
            [2, 'Liza P.', 5, 'The sunset view from the rooftop was perfect for our date.', '2026-09-20'],
            [2, 'Jun D.', 4, 'Great food, a little pricey.', '2026-10-02'],
            [3, 'Grace T.', 4, 'Affordable and peaceful. Good for budget dates.', '2026-09-15'],
            [5, 'Paul M.', 4, 'Clean rooms and friendly staff.', '2026-08-30'],
            [7, 'Kim L.', 5, 'Best pasta in Tagum! Very intimate place.', '2026-10-05'],
            [10, 'Rhea C.', 5, 'Spacious room, very romantic stay.', '2026-09-09'],
            [11, 'Dan V.', 4, 'Beautiful sunset and very relaxing.', '2026-10-01'],
        ];

        foreach ($reviews as [$estId, $who, $rating, $comment, $date]) {
            Review::forceCreate([
                'reservation_id' => null,
                'customer_id' => $reviewers[$who],
                'establishment_id' => $estId,
                'rating' => $rating,
                'comment' => $comment,
                'created_at' => $date.' 12:00:00',
                'updated_at' => $date.' 12:00:00',
            ]);
            
        }

        $start = today();
$end = $start->copy()->addMonthsNoOverflow(12)->subDay();
foreach (Establishment::all() as $e) {
    $e->update(['approval_status' => 'approved', 'approved_at' => now(),
                'listing_starts_at' => $start, 'listing_expires_at' => $end]);
    $e->contracts()->create(['type' => 'initial', 'duration_months' => 12, 'status' => 'approved',
                             'starts_at' => $start, 'ends_at' => $end, 'decided_at' => now()]);
        }
        
    }
}