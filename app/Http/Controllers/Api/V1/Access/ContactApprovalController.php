<?php

namespace App\Http\Controllers\Api\V1\Access;

use App\Http\Controllers\ApiController;
use App\Services\Access\AccessRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Emergency contacts answering a request. Two doors:
 *  - in the app (logged in, matched by phone number): index + approve
 *  - from the SMS link (no account): show + approveByLink. `show` only DESCRIBES the request.
 *    Approval is a separate POST, because link scanners open every link they see.
 */
class ContactApprovalController extends ApiController
{
    public function __construct(private readonly AccessRequestService $requests) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->requests->pendingForContactUser($request->user('web'))
            ->map(fn ($r) => $this->requests->summary($r))->all();

        return $this->ok(['requests' => $list]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $this->requests->approveAsContactUser($request->user('web'), $id);

        return $this->ok(null, 'Access approved.');
    }

    public function show(string $token): JsonResponse
    {
        return $this->ok($this->requests->describeByToken($token));
    }

    public function approveByLink(string $token): JsonResponse
    {
        $this->requests->approveByToken($token);

        return $this->ok(null, 'Access approved. Thank you.');
    }
}
