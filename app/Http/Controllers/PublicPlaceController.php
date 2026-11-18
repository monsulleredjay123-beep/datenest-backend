<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Public (walay login) para sa landing page: mga lugar sa usa ka area, pananglitan ang Rotary. */
class PublicPlaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'area' => ['nullable', Rule::in(array_keys(Establishment::AREAS))],
            'category' => ['nullable', Rule::in(Establishment::CATEGORIES)],
        ]);

        $places = Establishment::query()
            ->listed() // active + approved + wala pa ma-expire ang contract
            ->when($data['area'] ?? null, fn ($q, $area) => $q->where('area', $area))
            ->when($data['category'] ?? null, fn ($q, $cat) => $q->where('category', $cat))
            ->with(['firstPhoto', 'owner:id,logo'])
            ->orderByDesc('avg_rating')
            ->orderBy('name')
            ->limit(12)
            ->get();

        return response()->json([
            'data' => $places->map(fn (Establishment $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'category' => $e->category,
                'address' => $e->address,
                'lat' => (float) $e->lat,
                'lng' => (float) $e->lng,
                'opening_time' => substr((string) $e->opening_time, 0, 5),
                'closing_time' => substr((string) $e->closing_time, 0, 5),
                'avg_rating' => (float) $e->avg_rating,
                'price' => $e->price !== null ? (float) $e->price : null,
                'photo' => $e->firstPhoto?->url,
                'logo' => $e->owner?->logo,
            ])->values(),
        ]);
    }
}