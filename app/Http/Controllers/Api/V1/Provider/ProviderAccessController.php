<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Access\CreateSessionRecordRequest;
use App\Http\Requests\Api\V1\Access\ScanCardRequest;
use App\Http\Requests\Api\V1\Access\SubmitCodeRequest;
use App\Models\AccessRequest;
use App\Models\CareSession;
use App\Models\Organisation;
use App\Services\Access\AccessRequestService;
use App\Services\Access\SessionService;
use App\Services\Records\RecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The hospital side: scan a card, wait for the answer, use the session, check out. */
class ProviderAccessController extends ApiController
{
    public function __construct(
        private readonly AccessRequestService $requests,
        private readonly SessionService $sessions,
        private readonly RecordService $records,
    ) {}

    public function scan(ScanCardRequest $request): JsonResponse
    {
        $org = $this->organisation($request);

        $accessRequest = $this->requests->scan($request->user('web'), $org, $request->validated());

        return $this->created($this->present($accessRequest), 'Request sent.');
    }

    /** Poll this while waiting. It also moves the request on (patient -> contacts, expiry). */
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->ok($this->present($this->requests->forOrganisation($this->organisation($request), $id)));
    }

    public function submitCode(SubmitCodeRequest $request, string $id): JsonResponse
    {
        $session = $this->requests->submitCode($this->organisation($request), $id, $request->validated('code'));

        return $this->ok(['sessionId' => $session->id], 'Access approved.');
    }

    public function sessions(Request $request): JsonResponse
    {
        $list = $this->sessions->activeForOrganisation($this->organisation($request))->map(fn (CareSession $s) => [
            'id' => $s->id,
            'patientName' => $s->patient->username,
            'personalId' => $s->patient->personal_id,
            'startedAt' => $s->started_at->toIso8601String(),
        ])->all();

        return $this->ok(['sessions' => $list]);
    }

    public function essentials(Request $request, string $id): JsonResponse
    {
        return $this->ok($this->sessions->essentials($this->organisation($request), $id));
    }

    public function writeRecord(CreateSessionRecordRequest $request, string $id): JsonResponse
    {
        $session = $this->sessions->openFor($this->organisation($request), $id);
        $record = $this->records->createInSession($session, $request->user('web'), $request->validated());

        return $this->created(['record' => RecordService::present($record)], 'Record saved.');
    }

    public function checkOut(Request $request, string $id): JsonResponse
    {
        $this->sessions->checkOut($this->organisation($request), $id);

        return $this->ok(null, 'Checked out.');
    }

    private function organisation(Request $request): Organisation
    {
        return $request->user('web')->organisation() ?? throw new ApiException(ErrorCode::Forbidden);
    }

    /** @return array<string, mixed> */
    private function present(AccessRequest $r): array
    {
        return [
            'requestId' => $r->id,
            'status' => $r->status->value,
            'stage' => $r->stage->value,
            'expiresAt' => $r->expires_at->toIso8601String(),
            'sessionId' => $r->care_session_id,
            'attemptsLeft' => max(0, (int) config('onehealth.access.code_max_attempts') - $r->code_attempts),
        ];
    }
}
