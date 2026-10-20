<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;

/**
 * Hands the browser its XSRF-TOKEN cookie (the PreventRequestForgery middleware attaches it
 * to this response). The app sends that value back in the X-XSRF-TOKEN header on every POST.
 */
class CsrfController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        return $this->ok(null, 'CSRF cookie set.');
    }
}
