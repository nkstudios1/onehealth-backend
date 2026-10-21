<?php

namespace App\Http\Controllers\Api\V1\Access;

use App\Http\Controllers\ApiController;
use App\Models\CareSession;
use App\Services\Access\AccessRequestService;
use App\Services\Access\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the patient sees and does about access: pending requests, who has access now, ending it. */
class PatientAccessController extends ApiController
{
    public function __construct(
        private readonly AccessRequestService $requests,
        private readonly SessionService $sessions,
    ) {}

    public function pending(Request $request): JsonResponse
    {
        $list = $this->requests->pendingForPatient($request->user('web')->patient)->map(fn ($r) => [
            ...$this->requests->summary($r),
            'stage' => $r->stage->value,
        ])->all();

        return $this->ok(['requests' => $list]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $session = $this->requests->approveAsPatient($request->user('web')->patient, $id);

        return $this->ok(['session' => $this->presentSession($session)], 'Access approved.');
    }

    public function deny(Request $request, string $id): JsonResponse
    {
        $this->requests->denyAsPatient($request->user('web')->patient, $id);

        return $this->ok(null, 'Access denied.');
    }

    public function sessions(Request $request): JsonResponse
    {
        $active = $request->query('status') === 'active';
        $list = $this->sessions->forPatient($request->user('web')->patient, $active)
            ->map(fn (CareSession $s) => $this->presentSession($s))->all();

        return $this->ok(['sessions' => $list]);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $this->sessions->revoke($request->user('web')->patient, $id);

        return $this->ok(null, 'Access ended.');
    }

    /** @return array<string, mixed> */
    private function presentSession(CareSession $s): array
    {
        return [
            'id' => $s->id,
            'organisationName' => $s->organisation->name,
            'accessLevel' => $s->access_level->value,
            'method' => $s->access_method,
            'status' => $s->status->value,
            'startedAt' => $s->started_at->toIso8601String(),
            'endedAt' => $s->ended_at?->toIso8601String(),
        ];
    }
}
