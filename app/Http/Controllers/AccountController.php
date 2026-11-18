<?php

namespace App\Http\Controllers;

use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Customer ug establishment owner (naka-login): usba ang password o i-delete ang kaugalingong account. */
class AccountController extends Controller
{
    /** I-email ang code sa email sa user (password_change | account_delete). */
    public function requestOtp(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', Rule::in(['password_change', 'account_delete'])],
        ]);

        $user = $request->user();
        $devCode = $otp->send($user->email, $data['purpose']);

        [$name, $domain] = array_pad(explode('@', $user->email, 2), 2, '');
        $masked = substr($name, 0, 2).str_repeat('*', max(1, strlen($name) - 2)).'@'.$domain;

        return response()->json([
            'message' => "We sent a 6-digit code to {$masked}.",
            'resend_in' => OtpService::RESEND_SECONDS,
        ] + ($devCode ? ['dev_code' => $devCode] : []));
    }

    public function changePassword(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'otp' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ], ['password.confirmed' => 'Passwords do not match.']);

        $user = $request->user();

        if (! $otp->verify($user->email, 'password_change', $data['otp'])) {
            throw ValidationException::withMessages(['otp' => 'The code is incorrect or has expired. Request a new one.']);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Your new password must be different from the current one.']);
        }

        $user->password = $data['password']; // gi-hash sa 'hashed' cast
        $user->save();

        // I-logout ang ubang devices; ang karon nga session magpabilin
        $current = $request->user()->currentAccessToken();
        $user->tokens()->when($current?->id ?? null, fn ($q, $id) => $q->where('id', '!=', $id))->delete();

        return response()->json(['message' => 'Your password has been changed.']);
    }

    /** I-delete ang kaugalingong account (kauban ang listing, reservations ug reviews). */
    public function destroy(Request $request, OtpService $otp): JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            abort(403, 'Admin accounts cannot be deleted here.');
        }

        $data = $request->validate(['otp' => ['required', 'digits:6']]);

        if (! $otp->verify($user->email, 'account_delete', $data['otp'])) {
            throw ValidationException::withMessages(['otp' => 'The code is incorrect or has expired. Request a new one.']);
        }

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->delete(); // cascade: establishments, photos, menus, reservations, reviews
        });

        return response()->json(['message' => 'Your account has been deleted.']);
    }
}