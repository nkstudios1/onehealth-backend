<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to certain account types. Put after `auth:web`.
 *
 *   Route::middleware(['auth:web', 'active', 'type:patient'])
 *   Route::middleware(['auth:web', 'active', 'type:org_admin,org_staff'])
 */
class EnsureUserType
{
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $user = $request->user('web');

        $allowed = array_map(fn (string $t) => UserType::from($t), $types);

        if ($user === null || ! in_array($user->type, $allowed, true)) {
            throw new ApiException(ErrorCode::Forbidden);
        }

        return $next($request);
    }
}
