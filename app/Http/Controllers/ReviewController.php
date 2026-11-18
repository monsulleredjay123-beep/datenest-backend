<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReviewResource;
use App\Models\Establishment;
use App\Models\Reservation;
use App\Models\Review;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReviewController extends Controller
{
    /** Completed ra, ug usa ra ka review kada reservation */
    public function store(Request $request, Reservation $reservation): JsonResponse
    {
        Gate::authorize('own', $reservation);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        if ($reservation->status !== 'Completed') {
            abort(422, 'Only completed reservations can be reviewed.');
        }
        if ($reservation->review()->exists()) {
            abort(422, 'Only one review is allowed per reservation.');
        }

        $review = DB::transaction(function () use ($reservation, $data, $request) {
            $review = Review::create([
                'reservation_id' => $reservation->id,
                'customer_id' => $request->user()->id,
                'establishment_id' => $reservation->establishment_id,
                'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null,
            ]);

            // I-recalculate ang average rating sa establishment
            $avg = (float) Review::where('establishment_id', $reservation->establishment_id)->avg('rating');
            Establishment::whereKey($reservation->establishment_id)->update([
                'avg_rating' => round($avg, 1),
            ]);

            return $review;
        });

        $reservation->loadMissing('establishment');
        Notifier::to($reservation->establishment->owner_id, 'review_received', 'New review',
            "{$request->user()->name} rated {$reservation->establishment->name} {$data['rating']}/5.", '/owner/reviews');

        return (new ReviewResource($review->load('customer')))
            ->response()
            ->setStatusCode(201);
    }
}