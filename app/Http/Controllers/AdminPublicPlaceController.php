<?php

namespace App\Http\Controllers;

use App\Http\Resources\EstablishmentResource;
use App\Models\Amenity;
use App\Models\Establishment;
use App\Models\Preference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Public places (park, beach, viewpoint, plaza, mga destination sa mga date).
 * Ang ADMIN ra ang mo-add. Walay reservation, walay business permit, walay contract ug walay approval:
 * live dayon human i-save. Ang owner_id kay ang admin nga nag-add.
 */
class AdminPublicPlaceController extends Controller
{
    private const WITH = ['preferences', 'amenities', 'photos'];

    private const MAX_PHOTOS = 10;

    // Ang app kay para sa Pilipinas: ang pin kinahanglan naa sa sulod sa Pilipinas
    private const LAT_RANGE = [4.5, 21.5];
    private const LNG_RANGE = [116.0, 127.0];

    // Gamay nga kalainan (mga 30 metros) = parehas nga pin
    private const SAME_SPOT = 0.0003;

    /** Tanang public place (naka-hide man o dili) */
    public function index(): AnonymousResourceCollection
    {
        $places = Establishment::where('is_private', false)
            ->with(self::WITH)
            ->orderByDesc('id')
            ->get();

        return EstablishmentResource::collection($places);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $place = DB::transaction(function () use ($request, $data) {
            $place = Establishment::create($this->columns($data) + [
                'owner_id' => $request->user()->id,
                'category' => 'destination',
                'is_private' => false,
                'is_active' => true,
                'avg_rating' => 0,
                'approval_status' => 'approved', // admin na ang nag-add, walay review
                'approved_at' => now(),
                'reservation_fee' => null,
            ]);
            $this->syncRelations($place, $data);

            return $place;
        });

        return (new EstablishmentResource($place->load(self::WITH)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Establishment $establishment): EstablishmentResource
    {
        $this->ensurePublic($establishment);

        $data = $this->validated($request, $establishment);

        DB::transaction(function () use ($establishment, $data) {
            $establishment->update($this->columns($data));
            $this->syncRelations($establishment, $data);
        });

        return new EstablishmentResource($establishment->fresh(self::WITH));
    }

    /** I-hide / i-show sa customers (dili ma-delete) */
    public function toggle(Establishment $establishment): EstablishmentResource
    {
        $this->ensurePublic($establishment);

        $establishment->update(['is_active' => ! $establishment->is_active]);

        return new EstablishmentResource($establishment->fresh(self::WITH));
    }

    public function destroy(Establishment $establishment): JsonResponse
    {
        $this->ensurePublic($establishment);

        $name = $establishment->name;
        $establishment->delete(); // ang cascade sa database mo-delete sa photos, reviews ug uban pa

        return response()->json(['message' => "\"{$name}\" was deleted."]);
    }

    /** Public place ra ang pwede usbon dinhi. Ang private establishment naa sa owner ug sa Registrations. */
    private function ensurePublic(Establishment $establishment): void
    {
        if ($establishment->is_private) {
            abort(403, 'This is a private establishment. It is managed by its owner.');
        }
    }

    private function validated(Request $request, ?Establishment $current = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'min:3', 'max:255',
                // Walay duha ka public place nga parehas ug ngalan
                Rule::unique('establishments', 'name')
                    ->where(fn ($q) => $q->where('is_private', false))
                    ->ignore($current?->id),
            ],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'address' => ['required', 'string', 'min:5', 'max:255'],
            'area' => ['nullable', Rule::in(array_keys(Establishment::AREAS))],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'opening_time' => ['required', 'date_format:H:i'],
            'closing_time' => ['required', 'date_format:H:i', 'different:opening_time'],
            // Entrance fee (optional): walay presyo = libre
            'price' => ['nullable', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
            'preferences' => ['nullable', 'array', 'max:30'],
            'preferences.*' => ['string', 'exists:preferences,name'],
            'amenities' => ['nullable', 'array', 'max:30'],
            'amenities.*' => ['string', 'min:2', 'max:50', 'regex:/^[\pL\pN][\pL\pN\s&\'’\-\/().,+]*$/u'],
            // Kinahanglan ug labing menos 1 ka picture (ang una = cover). Ang customer makakita sa tanan sa slider.
            'photos' => ['required', 'array', 'min:1', 'max:'.self::MAX_PHOTOS],
            'photos.*' => ['string', 'max:2000000', 'regex:/^(https?:\/\/|data:image\/(jpeg|png|webp);base64,)/i'],
        ], [
            'name.unique' => 'A public place with this name already exists.',
            'name.min' => 'The name must be at least 3 characters.',
            'description.min' => 'Please write a description of at least 10 characters.',
            'address.min' => 'Please enter the full address.',
            'closing_time.different' => 'The opening and closing time cannot be the same. For a place that is always open, use 00:00 to 23:59.',
            'price.decimal' => 'The entrance fee can have at most 2 decimal places.',
            'price.max' => 'The entrance fee is too high.',
            'photos.required' => 'Please add at least 1 photo.',
            'photos.min' => 'Please add at least 1 photo.',
            'photos.max' => 'You can add up to '.self::MAX_PHOTOS.' photos only.',
        ]);

        // Logic 1: ang pin kinahanglan naa sa Pilipinas
        if ($data['lat'] < self::LAT_RANGE[0] || $data['lat'] > self::LAT_RANGE[1]
            || $data['lng'] < self::LNG_RANGE[0] || $data['lng'] > self::LNG_RANGE[1]) {
            throw ValidationException::withMessages([
                'lat' => 'The pin must be inside the Philippines. Please pin the exact place on the map.',
            ]);
        }

        // Logic 2: dili pwede parehas nga pin sa lain nga public place
        $near = Establishment::where('is_private', false)
            ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
            ->whereBetween('lat', [$data['lat'] - self::SAME_SPOT, $data['lat'] + self::SAME_SPOT])
            ->whereBetween('lng', [$data['lng'] - self::SAME_SPOT, $data['lng'] + self::SAME_SPOT])
            ->first();
        if ($near) {
            throw ValidationException::withMessages([
                'lat' => "\"{$near->name}\" is already pinned at this spot. Please move the pin or edit that place instead.",
            ]);
        }

        return $data;
    }

    private function columns(array $data): array
    {
        return [
            'name' => (string) Str::of($data['name'])->squish(),
            'description' => trim($data['description']),
            'address' => (string) Str::of($data['address'])->squish(),
            'area' => $data['area'] ?? null,
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'opening_time' => $data['opening_time'],
            'closing_time' => $data['closing_time'],
            'price' => $data['price'] ?? null,
        ];
    }

    private function syncRelations(Establishment $e, array $data): void
    {
        $e->preferences()->sync(Preference::whereIn('name', $data['preferences'] ?? [])->pluck('id'));

        $ids = [];
        foreach ($data['amenities'] ?? [] as $raw) {
            $name = (string) Str::of($raw)->squish();
            if ($name === '') {
                continue;
            }
            $amenity = Amenity::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                ?? Amenity::create(['name' => $name]);
            $ids[$amenity->id] = $amenity->id;
        }
        $e->amenities()->sync(array_values($ids));

        // Ang pagkasunod-sunod sa photos mao ang pagkasunod sa slider (ang una = cover)
        $e->photos()->delete();
        foreach (array_values(array_unique($data['photos'])) as $url) {
            $e->photos()->create(['url' => $url]);
        }
    }
}