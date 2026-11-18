<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\User;
use App\Services\Notifier;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthController extends Controller
{
    /** Letters, space, tuldok, apostrophe, hyphen (Ñ, Dela Cruz, O'Neil, Anne-Marie) */
    public const NAME_REGEX = '/^\pL[\pL\pM\s.\'’-]*$/u';

    public const MIN_AGE = 18;

    /** Step 1 sa register: i-email ang verification code. */
    public function sendRegisterOtp(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        $devCode = $otp->send($data['email'], 'register');

        return response()->json($this->otpResponse($data['email'], $devCode));
    }

    /** Step 2: kompleto nga form + code gikan sa email. */
    public function register(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:100', 'regex:'.self::NAME_REGEX],
            'last_name' => ['required', 'string', 'min:2', 'max:100', 'regex:'.self::NAME_REGEX],
            'birthdate' => [
                'required', 'date_format:Y-m-d', 'after:1900-01-01',
                'before_or_equal:'.now()->subYears(self::MIN_AGE)->toDateString(),
            ],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'otp' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            // Ang admin role dili pwede ma-register sa public form
            'role' => ['required', Rule::in(['customer', 'establishment_admin'])],
            // Logo: kinahanglan sa owner (logo sa establishment), optional sa customer
            'logo' => [
                Rule::requiredIf(fn () => $request->input('role') === 'establishment_admin'),
                'nullable', 'string', 'max:400000', 'regex:/^data:image\/(jpeg|png|webp);base64,/i',
            ],
            // Business permit: kinahanglan sa owner (image data URL), walay permit ang customer
            'business_permit' => [
                Rule::requiredIf(fn () => $request->input('role') === 'establishment_admin'),
                'nullable', 'string', 'max:3000000', 'regex:/^data:image\/(jpeg|png|webp);base64,/i',
            ],
        ], [
            'birthdate.before_or_equal' => 'You must be at least '.self::MIN_AGE.' years old to register.',
            'first_name.regex' => 'First name may only contain letters, spaces, periods, apostrophes and hyphens.',
            'last_name.regex' => 'Last name may only contain letters, spaces, periods, apostrophes and hyphens.',
            'logo.required' => 'Please upload your establishment logo.',
            'business_permit.required' => 'Please upload your business permit.',
            'password.confirmed' => 'Passwords do not match.',
        ]);

        if (! $otp->verify($data['email'], 'register', $data['otp'])) {
            throw ValidationException::withMessages(['otp' => 'The code is incorrect or has expired. Request a new one.']);
        }

        $isOwner = $data['role'] === 'establishment_admin';

        $user = User::create([
            'name' => trim($data['first_name']).' '.trim($data['last_name']),
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'birthdate' => $data['birthdate'],
            'email' => strtolower($data['email']),
            'email_verified_at' => now(),
            'password' => $data['password'], // gi-hash sa 'hashed' cast
            'role' => $data['role'],
            'logo' => $data['logo'] ?? null,
            // Customer walay permit
            'business_permit' => $isOwner ? ($data['business_permit'] ?? null) : null,
        ]);

        // Nag-register balik gamit ang email sa na-delete nga account: bag-ong account na ni
        DB::table('deleted_accounts')->where('email', $user->email)->delete();

        if ($isOwner) {
            // Ipahibalo sa admin: pila na ang nag-register nga owner ug pila ang naghulat sa review
            $newThisWeek = User::where('role', 'establishment_admin')->where('created_at', '>=', now()->subDays(7))->count();
            $totalOwners = User::where('role', 'establishment_admin')->count();
            $waiting = Establishment::where('approval_status', 'pending')->count();

            Notifier::admins(
                'owner_registered',
                'New establishment owner registered',
                "{$user->name} ({$user->email}) registered as an establishment owner."
                ." New owners in the last 7 days: {$newThisWeek} (total owners: {$totalOwners})."
                ." Registrations waiting for review: {$waiting}.",
                '/admin/registrations',
            );

            Notifier::to($user, 'welcome_owner', 'Welcome to DateNest!',
                'Next, register your establishment. Our admin will check your documents. After it is approved, your establishment goes live. Advertising is free.',
                '/owner/listing');
        }

        return response()->json([
            'token' => $user->createToken('datenest')->plainTextToken,
            'user' => $user->authPayload(),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            // Na-delete sa admin ang account: ipahibalo (dili lang "Incorrect email or password")
            if (DB::table('deleted_accounts')->where('email', mb_strtolower(trim($data['email'])))->exists()) {
                return response()->json([
                    'message' => 'This account has been deleted by the administrator. You can register again to use DateNest.',
                ], 403);
            }

            return response()->json(['message' => 'Incorrect email or password.'], 401);
        }

        if (! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Incorrect email or password.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        return response()->json([
            'token' => $user->createToken('datenest')->plainTextToken,
            'user' => $user->authPayload(),
        ]);
    }

    /** Forgot password step 1: i-email ang code (parehas ang tubag bisan wala ang email, para dili ma-guess). */
    public function forgotPassword(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $user = User::where('email', strtolower($data['email']))->where('is_active', true)->first();

        $devCode = null;
        if ($user) {
            try {
                $devCode = $otp->send($user->email, 'password_reset');
            } catch (HttpException $e) {
                // Rate limit (429): parehas gihapon ang tubag. Ang ubang error ipadayon.
                if ($e->getStatusCode() !== 429) {
                    throw $e;
                }
            }
        }

        return response()->json($this->otpResponse($data['email'], $devCode, true));
    }

    /** Forgot password step 2: code + bag-ong password. */
    public function resetPassword(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'otp' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ], ['password.confirmed' => 'Passwords do not match.']);

        $user = User::where('email', strtolower($data['email']))->where('is_active', true)->first();

        if (! $user || ! $otp->verify($user->email, 'password_reset', $data['otp'])) {
            throw ValidationException::withMessages(['otp' => 'The code is incorrect or has expired. Request a new one.']);
        }

        $user->password = $data['password']; // gi-hash sa 'hashed' cast
        $user->save();
        $user->tokens()->delete(); // i-logout ang tanang devices

        return response()->json(['message' => 'Your password has been reset. You can log in now.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->authPayload());
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    private function otpResponse(string $email, ?string $devCode, bool $generic = false): array
    {
        return [
            'message' => $generic
                ? 'If that email is registered, we sent a 6-digit code to it.'
                : 'We sent a 6-digit code to '.$this->maskEmail($email).'.',
            'resend_in' => OtpService::RESEND_SECONDS,
        ] + ($devCode ? ['dev_code' => $devCode] : []);
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return substr($name, 0, 2).str_repeat('*', max(1, strlen($name) - 2)).'@'.$domain;
    }
}