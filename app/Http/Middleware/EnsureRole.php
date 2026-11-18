<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // 401 = i-logout dayon sa Angular (deactivated na ang account)
        if (! $user->is_active) {
            return response()->json(['message' => 'This account has been deactivated.'], 401);
        }

        if (! in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'You are not allowed to access this resource.'], 403);
        }

        return $next($request);
    }
}