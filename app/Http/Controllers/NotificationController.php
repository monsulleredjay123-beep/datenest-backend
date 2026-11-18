<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mga notification sa naka-login nga user (bell). Tanang role. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $uid = $request->user()->id;

        $items = UserNotification::where('user_id', $uid)
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return response()->json([
            'unread_count' => UserNotification::where('user_id', $uid)->whereNull('read_at')->count(),
            'data' => $items->map(fn (UserNotification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'link' => $n->link,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function read(Request $request, UserNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403, 'This is not your notification.');

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}