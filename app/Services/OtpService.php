<?php

namespace App\Services;

use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class OtpService
{
    public const TTL_MINUTES = 10;
    public const RESEND_SECONDS = 60;
    public const MAX_ATTEMPTS = 5;

    /**
     * Mo-generate ug 6-digit code ug i-email.
     * Ibalik ang code ra kung MAIL_MAILER=log ug APP_ENV=local (para sa testing sa screen).
     */
    public function send(string $email, string $purpose): ?string
    {
        $email = strtolower($email);

        $last = OtpCode::where('email', $email)->where('purpose', $purpose)->latest('id')->first();
        if ($last) {
            $wait = self::RESEND_SECONDS - (now()->timestamp - $last->created_at->timestamp);
            if ($wait > 0) {
                abort(429, "Please wait {$wait} seconds before requesting a new code.");
            }
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::where('email', $email)->where('purpose', $purpose)->delete();
        $record = OtpCode::create([
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => $this->hash($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        try {
            Mail::to($email)->send(new OtpCodeMail($code, $purpose, self::TTL_MINUTES));
        } catch (Throwable $e) {
            Log::error('OTP email failed: '.$e->getMessage());
            $record->delete();
            abort(502, 'We could not send the email right now. Please try again in a moment.');
        }

        return (config('mail.default') === 'log' && app()->environment('local')) ? $code : null;
    }

    public function verify(string $email, string $purpose, string $code): bool
    {
        $otp = OtpCode::where('email', strtolower($email))->where('purpose', $purpose)->latest('id')->first();

        if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $otp->increment('attempts');

        if (! hash_equals($otp->code_hash, $this->hash($code))) {
            return false;
        }

        $otp->delete(); // usa ra ka gamit
        return true;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}