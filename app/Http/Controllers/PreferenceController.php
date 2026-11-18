<?php

namespace App\Http\Controllers;

use App\Models\Preference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Dynamic preferences (parehas sa amenities): ang owner ug admin pwede mag-add ug bag-ong preference.
 * Ang tanan nga role makakita sa tibuok listahan (para sa chips sa Discover ug sa listing form).
 */
class PreferenceController extends Controller
{
    private const MAX_TOTAL = 100;

    public function index(): JsonResponse
    {
        return response()->json(['data' => Preference::orderBy('id')->pluck('name')->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\pL\pN][\pL\pN\s&\'’\-\/().,+]*$/u'],
        ], [
            'name.min' => 'The preference must be at least 2 characters.',
            'name.regex' => 'Use letters, numbers and basic symbols only.',
        ]);

        $name = (string) Str::of($data['name'])->squish();

        // Kung naa na (bisan lahi ang capital letters), gamita ra ang naa na
        $existing = Preference::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($existing) {
            return response()->json(['data' => $existing->name]);
        }

        if (Preference::count() >= self::MAX_TOTAL) {
            return response()->json(['message' => 'The preference list is full. Please choose from the existing ones.'], 422);
        }

        $pref = Preference::create(['name' => $name]);

        return response()->json(['data' => $pref->name], 201);
    }
}