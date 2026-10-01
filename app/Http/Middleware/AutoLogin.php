<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * This app runs as a single-operator local tool, so the login screen is skipped:
 * every guest request is signed in as the first seeded user automatically.
 */
class AutoLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            $user = User::query()->orderBy('id')->first();

            if ($user) {
                Auth::login($user);
            }
        }

        return $next($request);
    }
}
