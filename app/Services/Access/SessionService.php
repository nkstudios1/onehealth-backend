<?php

namespace App\Services\Access;

use App\Enums\AuditEvent;
use App\Enums\CareSessionStatus;
use App\Enums\ErrorCode;
use App\Enums\RecordVisibility;
use App\Exceptions\ApiException;
use App\Models\CareSession;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\Record;
use App\Services\Records\RecordService;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Care sessions: what an organisation may do while it has access to a patient.
 * A session is valid only while `active`. A limited session reads PUBLIC records only and is
 * told how many private ones exist (a count, never content), so staff know to ask.
 */
class SessionService
{
    /** @return Collection<int, CareSession> */
    public function activeForOrganisation(Organisation $org): Collection
    {
        return CareSession::with('patient')
            ->where('organisation_id', $org->id)
            ->where('status', CareSessionStatus::Active->value)
            ->latest('started_at')
            ->get()
            ->filter(fn (CareSession $s) => $this->closeIfExpired($s)->isOpen())
            ->values();
    }

    /** @return Collection<int, CareSession> */
    public function forPatient(Patient $patient, bool $activeOnly = false): Collection
    {
        $query = CareSession::with('organisation')->where('patient_id', $patient->id)->latest('started_at');
        if ($activeOnly) {
            $query->where('status', CareSessionStatus::Active->value);
        }

        return $query->get()->map(fn (CareSession $s) => $this->closeIfExpired($s))
            ->filter(fn (CareSession $s) => ! $activeOnly || $s->isOpen())
            ->values();
    }

    /** Only the organisation that holds a session can use it, and only while it is open. */
    public function openFor(Organisation $org, string $id): CareSession
    {
        $session = Str::isUuid($id) ? CareSession::where('organisation_id', $org->id)->find($id) : null;

        if ($session === null) {
            throw new ApiException(ErrorCode::SessionNotFound);
        }

        if (! $this->closeIfExpired($session)->isOpen()) {
            throw new ApiException(ErrorCode::SessionClosed);
        }

        return $session;
    }

    /** What a hospital sees about the patient during a session. */
    public function essentials(Organisation $org, string $id): array
    {
        $session = $this->openFor($org, $id);
        $patient = Patient::findOrFail($session->patient_id);

        $records = Record::where('patient_id', $patient->id);
        $public = (clone $records)->where('visibility', RecordVisibility::Public->value)->orderBy('category')->latest()->get();
        $privateCount = (clone $records)->where('visibility', RecordVisibility::Private->value)->count();

        Audit::record(AuditEvent::PatientDataViewed, $session->opened_by, [
            'session_id' => $session->id, 'patient_id' => $patient->id, 'public_records' => $public->count(),
        ]);

        return [
            'sessionId' => $session->id,
            'accessLevel' => $session->access_level->value,
            'startedAt' => $session->started_at->toIso8601String(),
            'patient' => [
                'fullName' => $patient->fullname,
                'personalId' => $patient->personal_id,
                'dateOfBirth' => $patient->dob->toDateString(),
                'gender' => $patient->gender,
            ],
            'records' => $public->map(fn (Record $r) => RecordService::present($r))->all(),
            'privateRecordCount' => $privateCount,
        ];
    }

    public function checkOut(Organisation $org, string $id): void
    {
        $session = $this->openFor($org, $id);
        $session->update(['status' => CareSessionStatus::CheckedOut, 'ended_at' => now()]);

        Audit::record(AuditEvent::SessionCheckedOut, $session->opened_by, ['session_id' => $session->id]);
    }

    /** The patient can end any session at any time. */
    public function revoke(Patient $patient, string $id): void
    {
        $session = Str::isUuid($id) ? CareSession::where('patient_id', $patient->id)->find($id) : null;

        if ($session === null) {
            throw new ApiException(ErrorCode::SessionNotFound);
        }
        if ($session->status !== CareSessionStatus::Active) {
            throw new ApiException(ErrorCode::SessionClosed);
        }

        $session->update(['status' => CareSessionStatus::Revoked, 'ended_at' => now()]);

        Audit::record(AuditEvent::SessionRevoked, $patient->id, ['session_id' => $session->id]);
    }

    /** Close sessions nobody checked out of. Scheduler entry point. */
    public function autoCloseStale(): int
    {
        $cutoff = now()->subDays((int) config('onehealth.access.session_auto_close_days'));
        $n = 0;

        CareSession::where('status', CareSessionStatus::Active->value)
            ->where('started_at', '<', $cutoff)
            ->each(function (CareSession $s) use (&$n) {
                $s->update(['status' => CareSessionStatus::Expired, 'ended_at' => now()]);
                Audit::record(AuditEvent::SessionAutoClosed, null, ['session_id' => $s->id]);
                $n++;
            });

        return $n;
    }

    private function closeIfExpired(CareSession $session): CareSession
    {
        if ($session->status === CareSessionStatus::Active && $session->expires_at !== null && $session->expires_at->isPast()) {
            $session->update(['status' => CareSessionStatus::Expired, 'ended_at' => $session->expires_at]);
        }

        return $session;
    }
}
