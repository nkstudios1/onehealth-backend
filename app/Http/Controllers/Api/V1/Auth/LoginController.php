<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\LoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends ApiController
{
    public function __construct(private readonly LoginService $login) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->login->attempt($request->validated('identifier'), $request->validated('password'), $request);

        return $this->ok(['user' => (new UserResource($user))->resolve()], 'Logged in.');
    }

    public function me(): JsonResponse
    {
        return $this->ok(['user' => (new UserResource(Auth::guard('web')->user()))->resolve()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->login->logout($request);

        return $this->ok(null, 'Logged out.');
    }
}
