<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;

/**
 * Base class for every API controller. Controllers stay thin: validate with a
 * Form Request, call a service, return $this->ok(...). Business rules live in
 * app/Services, not here.
 */
abstract class ApiController extends Controller
{
    use ApiResponse;
}
