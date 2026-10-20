<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Put after `auth:web`. If an admin suspends an account while its owner is
 * logged in, their very next request is refused and the session is ended,
 * instead of working until the cookie expires.
 *
 *   Route::middleware(['auth:web', 'active'])->group(...)
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user !== null && ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            throw new ApiException(ErrorCode::AccountSuspended);
        }

        return $next($request);
    }
}
