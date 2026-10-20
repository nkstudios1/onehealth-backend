<?php

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id, returns it in the `X-Request-Id` header and adds it
 * to every log line. A client may send its own id (a UUID) so one user action
 * can be followed from the app into the logs. Anything that is not a UUID is
 * ignored, so nobody can inject text into our logs through this header.
 *
 * Registered globally (bootstrap/app.php) so even 404s and errors carry one.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Request-Id', '');
        $id = Str::isUuid($incoming) ? strtolower($incoming) : (string) Str::uuid();

        RequestId::set($id);
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
