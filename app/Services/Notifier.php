<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;

/**
 * Pag-himo ug notification sa user (makita sa bell sa app).
 * Ang pag-send dili mo-hunong sa main nga buhat kung naay sayop (gi-catch).
 */
class Notifier
{
    public static function to(User|int|null $user, string $type, string $title, string $body, ?string $link = null): void
    {
        $id = $user instanceof User ? $user->id : $user;
        if (! $id) {
            return;
        }

        try {
            UserNotification::create([
                'user_id' => $id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'link' => $link,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Ang tanang active nga admin */
    public static function admins(string $type, string $title, string $body, ?string $link = null): void
    {
        User::where('role', 'admin')->where('is_active', true)->pluck('id')
            ->each(fn ($id) => self::to($id, $type, $title, $body, $link));
    }
}