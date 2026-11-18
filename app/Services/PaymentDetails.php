<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation sa detalye sa bayad (parehas sa reservation fee ug ads).
 * DEMO: walay tinuod nga gateway. Ang card number/CVC dili i-save bisan asa; ang last 4 digits ra (gi-mask).
 */
class PaymentDetails
{
    public const METHODS = ['GCash', 'Maya', 'Card'];

    /** Demo: kining test card kanunay ma-decline, aron makita ang failed payment */
    public const DECLINED_TEST_CARD = '4000000000000002';

    /**
     * I-validate ang request. Return: ['method' => 'GCash', 'account' => '****4567'].
     *
     * @throws ValidationException
     */
    public static function validate(Request $request): array
    {
        $method = (string) $request->input('method');
        $wallet = in_array($method, ['GCash', 'Maya'], true);
        $card = $method === 'Card';

        $data = $request->validate([
            'method' => ['required', Rule::in(self::METHODS)],
            'mobile' => [Rule::requiredIf($wallet), 'nullable', 'regex:/^09\d{9}$/'],
            'card_name' => [Rule::requiredIf($card), 'nullable', 'string', 'min:2', 'max:60', 'regex:/^\pL[\pL\pM\s.\'’-]*$/u'],
            'card_number' => [Rule::requiredIf($card), 'nullable', 'string', 'max:25'],
            'expiry' => [Rule::requiredIf($card), 'nullable', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
            'cvc' => [Rule::requiredIf($card), 'nullable', 'digits_between:3,4'],
        ], [
            'mobile.required' => 'Please enter your '.$method.' mobile number.',
            'mobile.regex' => 'Enter a valid mobile number (11 digits, starts with 09).',
            'card_name.required' => 'Please enter the name on the card.',
            'card_name.regex' => 'The name on the card may only contain letters, spaces, periods, apostrophes and hyphens.',
            'card_number.required' => 'Please enter your card number.',
            'expiry.required' => 'Please enter the card expiry date.',
            'expiry.regex' => 'Enter the expiry date as MM/YY.',
            'cvc.required' => 'Please enter the card security code.',
            'cvc.digits_between' => 'The security code must be 3 or 4 digits.',
        ]);

        if ($wallet) {
            return ['method' => $method, 'account' => '****'.substr($data['mobile'], -4)];
        }

        $digits = preg_replace('/\D/', '', (string) $data['card_number']);

        if (strlen($digits) !== 16 || ! self::luhn($digits)) {
            throw ValidationException::withMessages(['card_number' => 'That card number is not valid. Please check it.']);
        }

        // Ang expiry: wala pa ma-expire (hangtod katapusan sa bulan)
        [$month, $year] = array_map('intval', explode('/', $data['expiry']));
        $endOfMonth = Carbon::create(2000 + $year, $month, 1)->endOfMonth();
        if ($endOfMonth->isPast()) {
            throw ValidationException::withMessages(['expiry' => 'This card has expired.']);
        }

        // Demo: ang test card nga mo-decline
        if ($digits === self::DECLINED_TEST_CARD) {
            throw ValidationException::withMessages(['card_number' => 'Your card was declined. Please use another card or payment method.']);
        }

        return ['method' => 'Card', 'account' => '****'.substr($digits, -4)];
    }

    /** Luhn checksum sa card number */
    public static function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }
}