<?php

namespace App\Http\Controllers;

use App\Http\Resources\EstablishmentResource;
use App\Http\Resources\ReviewResource;
use App\Models\Establishment;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class EstablishmentController extends Controller
{
    /**
     * Rule-based filtering and ranking:
     * 1. active ra, 2. category, 3. budget (walay presyo = ipabilin + flag),
     * 4. ihapa ang matched preferences, 5. sort: matched, dayon avg rating.
     */
    public function search(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'budget' => ['nullable', 'numeric', 'min:1'],
            'category' => ['nullable', Rule::in(Establishment::CATEGORIES)],
            'preferences' => ['nullable', 'array'],
            'preferences.*' => ['string', 'max:100'],
        ]);

        $budget = $data['budget'] ?? null;
        $category = $data['category'] ?? null;
        $preferences = $data['preferences'] ?? [];

        $establishments = Establishment::query()
            ->listed() // active + approved + wala pa ma-expire ang contract
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($budget, fn ($q) => $q->where(
                fn ($w) => $w->whereNull('price')->orWhere('price', '<=', $budget)
            ))
            ->withCount([
                'preferences as matched' => fn ($q) => $q->whereIn('preferences.name', $preferences),
            ])
            ->with(['preferences', 'amenities', 'photos', 'owner:id,logo'])
            ->orderByDesc('matched')
            ->orderByDesc('avg_rating')
            ->orderBy('name')
            ->get();

        return EstablishmentResource::collection($establishments);
    }

    public function show(Establishment $establishment): EstablishmentResource
    {
        if (! $establishment->hasActiveListing()) {
            abort(404, 'Establishment not found.');
        }

        return new EstablishmentResource($establishment->load(['preferences', 'amenities', 'photos', 'menuPhotos', 'owner:id,logo']));
    }

    public function reviews(Establishment $establishment): AnonymousResourceCollection
    {
        $reviews = Review::where('establishment_id', $establishment->id)
            ->with('customer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return ReviewResource::collection($reviews);
    }
}