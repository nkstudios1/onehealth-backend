<?php

namespace App\Services\Access;

use App\Contracts\SmsGateway;
use App\Enums\AccessLevel;
use App\Enums\AccessRequestStage;
use App\Enums\AccessRequestStatus;
use App\Enums\AuditEvent;
use App\Enums\CareSessionStatus;
use App\Enums\DecisionChannel;
use App\Enums\ErrorCode;
use App\Enums\NoticeChannel;
use App\Exceptions\ApiException;
use App\Models\AccessRequest;
use App\Models\AccessRequestNotice;
use App\Models\CareSession;
use App\Models\EmergencyContact;
use App\Models\HealthCard;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit;
use App\Support\Hmac;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scan -> request -> answer -> session.
 *
 *  1. A verified hospital scans a card. That creates an AccessRequest (it opens nothing).
 *  2. The patient is asked first (in the app, and by SMS).
 *  3. If the patient does not answer within `patient_wait_seconds`, every emergency contact is
 *     asked at once: SMS with a code and a link, plus the app if they have it.
 *  4. The first approval wins and opens a CareSession (read-only to public records).
 *
 * Every rule about who may answer and when lives here, so each controller stays thin.
 */
class AccessRequestService
{
    public function __construct(private readonly SmsGateway $sms) {}

    // ------------------------------------------------------------------ hospital side

    /**
     * @param  array{qrToken?: ?string, cardNumber?: ?string, dateOfBirth?: ?string}  $input
     */
    public function scan(User $actor, Organisation $org, array $input): AccessRequest
    {
        if (! $org->isVerified()) {
            throw new ApiException(ErrorCode::OrganisationNotVerified);
        }

        $card = $this->findCard($input);

        $this->guardScanRate($org, $card);

        if (! $card->isUsable()) {
            throw new ApiException(ErrorCode::CardNotActive);
        }

        $patient = Patient::findOrFail($card->owner_id);

        // Scanning twice while one is waiting returns the same request instead of spamming the patient.
        $existing = AccessRequest::where('organisation_id', $org->id)
            ->where('patient_id', $patient->id)
            ->where('status', AccessRequestStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $request = DB::transaction(function () use ($actor, $org, $card, $patient) {
            $request = AccessRequest::create([
                'patient_id' => $patient->id,
                'organisation_id' => $org->id,
                'requested_by' => $actor->id,
                'card_id' => $card->id,
                'status' => AccessRequestStatus::Pending,
                'stage' => AccessRequestStage::Patient,
                'expires_at' => now()->addMinutes((int) config('onehealth.access.request_ttl_minutes')),
            ]);

            AccessRequestNotice::create([
                'access_request_id' => $request->id,
                'recipient_type' => 'patient',
                'channel' => NoticeChannel::App,
                'sent_at' => now(),
            ]);

            return $request;
        });

        Audit::record(AuditEvent::AccessRequested, $actor->id, [
            'request_id' => $request->id, 'patient_id' => $patient->id, 'organisation_id' => $org->id,
        ]);

        $this->sendSms(
            (string) $patient->user->phone,
            "{$org->name} is requesting access to your OneHealth records. Open the app to approve or deny.",
        );

        return $request;
    }

    /** The hospital that raised a request (and only that hospital) can read its status. */
    public function forOrganisation(Organisation $org, string $id): AccessRequest
    {
        $request = Str::isUuid($id) ? AccessRequest::where('organisation_id', $org->id)->find($id) : null;

        if ($request === null) {
            throw new ApiException(ErrorCode::AccessRequestNotFound);
        }

        return $this->refresh($request);
    }

    /** The hospital types the code a contact read out to it. */
    public function submitCode(Organisation $org, string $id, string $code): CareSession
    {
        $request = $this->forOrganisation($org, $id);

        if (! $request->isPending()) {
            throw new ApiException(ErrorCode::AccessRequestClosed);
        }

        $max = (int) config('onehealth.access.code_max_attempts');

        // The wrong-guess counter must survive the exception, so count first, throw after commit.
        $match = DB::transaction(function () use ($request, $code, $max): ?AccessRequestNotice {
            $locked = AccessRequest::lockForUpdate()->findOrFail($request->id);

            if (! $locked->isPending() || $locked->code_attempts >= $max) {
                return null;
            }

            $notices = $locked->notices()->where('recipient_type', 'contact')->whereNotNull('code_hash')->whereNull('used_at')->get();
            foreach ($notices as $notice) {
                if (hash_equals((string) $notice->code_hash, Hmac::otp($notice->id, $code))) {
                    return $notice;
                }
            }

            $locked->increment('code_attempts');
            if ($locked->code_attempts >= $max) {
                $locked->update(['status' => AccessRequestStatus::Cancelled, 'decided_at' => now()]);
            }

            return null;
        });

        if ($match === null) {
            Audit::record(AuditEvent::AccessCodeFailed, $request->requested_by, ['request_id' => $request->id]);

            throw new ApiException(ErrorCode::ApprovalCodeInvalid);
        }

        return $this->approve($request->id, DecisionChannel::CodeEntered, contactId: $match->emergency_contact_id);
    }

    // ------------------------------------------------------------------ patient side

    /** @return Collection<int, AccessRequest> */
    public function pendingForPatient(Patient $patient): Collection
    {
        return AccessRequest::with('organisation')
            ->where('patient_id', $patient->id)
            ->where('status', AccessRequestStatus::Pending->value)
            ->latest()
            ->get()
            ->map(fn (AccessRequest $r) => $this->refresh($r))
            ->filter(fn (AccessRequest $r) => $r->isPending())
            ->values();
    }

    public function approveAsPatient(Patient $patient, string $id): CareSession
    {
        $request = $this->ownedByPatient($patient, $id);

        return $this->approve($request->id, DecisionChannel::PatientApp, byPatient: true);
    }

    public function denyAsPatient(Patient $patient, string $id): void
    {
        $request = $this->ownedByPatient($patient, $id);

        $denied = DB::transaction(function () use ($request) {
            $locked = AccessRequest::lockForUpdate()->findOrFail($request->id);
            if (! $locked->isPending()) {
                return false;
            }
            $locked->update([
                'status' => AccessRequestStatus::Denied, 'decided_by_patient' => true,
                'decided_via' => DecisionChannel::PatientApp, 'decided_at' => now(),
            ]);

            return true;
        });

        if (! $denied) {
            throw new ApiException(ErrorCode::AccessRequestClosed);
        }

        Audit::record(AuditEvent::AccessDenied, $patient->id, ['request_id' => $request->id]);
    }

    // ------------------------------------------------------------------ contact side

    /** What the approval page shows before the contact presses Approve. Approves nothing. */
    public function describeByToken(string $token): array
    {
        $notice = $this->noticeByToken($token);
        $request = $this->refresh($notice->request()->with(['organisation', 'patient'])->firstOrFail());

        if (! $request->isPending() || $request->stage !== AccessRequestStage::Contacts) {
            throw new ApiException(ErrorCode::AccessRequestClosed);
        }

        return $this->describe($request);
    }

    /** The contact pressed Approve on the page (a POST, never a plain link visit). */
    public function approveByToken(string $token): CareSession
    {
        $notice = $this->noticeByToken($token);
        $request = $this->refresh($notice->request()->firstOrFail());

        if (! $request->isPending() || $request->stage !== AccessRequestStage::Contacts) {
            throw new ApiException(ErrorCode::AccessRequestClosed);
        }

        return $this->approve($request->id, DecisionChannel::ContactLink, contactId: $notice->emergency_contact_id);
    }

    /**
     * Requests waiting on this person because they are someone's emergency contact AND have an
     * account (matched by phone number). They approve inside the app while logged in.
     *
     * @return Collection<int, AccessRequest>
     */
    public function pendingForContactUser(User $user): Collection
    {
        if ($user->phone === null) {
            return collect();
        }

        $contacts = EmergencyContact::where('phone', $user->phone)->get(['id', 'patient_id']);
        $contactIds = $contacts->pluck('id');

        // A request the patient left unanswered may be due to move on; do that now rather than
        // waiting for the next scheduler run, so the contact sees it as early as possible.
        AccessRequest::whereIn('patient_id', $contacts->pluck('patient_id'))
            ->where('status', AccessRequestStatus::Pending->value)
            ->where('stage', AccessRequestStage::Patient->value)
            ->each(fn (AccessRequest $r) => $this->refresh($r));

        return AccessRequest::with(['organisation', 'patient'])
            ->whereHas('notices', fn ($q) => $q->whereIn('emergency_contact_id', $contactIds))
            ->where('status', AccessRequestStatus::Pending->value)
            ->where('stage', AccessRequestStage::Contacts->value)
            ->latest()
            ->get()
            ->map(fn (AccessRequest $r) => $this->refresh($r))
            ->filter(fn (AccessRequest $r) => $r->isPending() && $r->stage === AccessRequestStage::Contacts)
            ->values();
    }

    public function approveAsContactUser(User $user, string $id): CareSession
    {
        $request = $this->pendingForContactUser($user)->firstWhere('id', $id);

        if ($request === null) {
            throw new ApiException(ErrorCode::AccessRequestNotFound);
        }

        $contactId = EmergencyContact::where('phone', $user->phone)
            ->where('patient_id', $request->patient_id)
            ->value('id');

        return $this->approve($request->id, DecisionChannel::ContactApp, contactId: $contactId);
    }

    // ------------------------------------------------------------------ time-based changes

    /**
     * Apply anything that changed with time: a request past its expiry dies, and one the patient
     * has not answered for `patient_wait_seconds` goes to the emergency contacts. Called whenever a
     * request is read, and every minute by the scheduler, so neither depends on someone polling.
     */
    public function refresh(AccessRequest $request): AccessRequest
    {
        if (! $request->isPending()) {
            return $request;
        }

        if ($request->expires_at->isPast()) {
            $request->update(['status' => AccessRequestStatus::Expired, 'decided_at' => now()]);

            return $request;
        }

        $waited = $request->created_at->diffInSeconds(now(), true);
        if ($request->stage === AccessRequestStage::Patient && $waited >= (int) config('onehealth.access.patient_wait_seconds')) {
            $this->escalate($request->id);

            return $request->fresh();
        }

        return $request;
    }

    /** Scheduler entry point. */
    public function processDue(): int
    {
        $n = 0;
        AccessRequest::where('status', AccessRequestStatus::Pending->value)->each(function (AccessRequest $r) use (&$n) {
            $before = [$r->status, $r->stage];
            $after = $this->refresh($r);
            if ([$after->status, $after->stage] !== $before) {
                $n++;
            }
        });

        return $n;
    }

    /** Ask every emergency contact at once. Safe to call twice: the second call does nothing. */
    public function escalate(string $id): void
    {
        $outbox = [];

        DB::transaction(function () use ($id, &$outbox) {
            $request = AccessRequest::with(['organisation.phones', 'patient'])->lockForUpdate()->find($id);

            if ($request === null || ! $request->isPending() || $request->stage !== AccessRequestStage::Patient) {
                return;
            }

            $request->update(['stage' => AccessRequestStage::Contacts, 'escalated_at' => now()]);

            $frontDesk = $request->organisation->phones()->whereNotNull('verified_at')->pluck('phone')->all();
            $base = rtrim((string) config('onehealth.access.approval_link_base'), '/');

            foreach ($request->patient->emergencyContacts as $contact) {
                $code = $this->newCode();
                $token = Str::random(40);

                $notice = AccessRequestNotice::create([
                    'access_request_id' => $request->id,
                    'recipient_type' => 'contact',
                    'emergency_contact_id' => $contact->id,
                    'channel' => NoticeChannel::Sms,
                    'token_hash' => Hmac::lookup('approval-token', $token),
                    'sent_at' => now(),
                ]);
                // The code is bound to this notice, so it can never be used for another request or contact.
                $notice->update(['code_hash' => Hmac::otp($notice->id, $code)]);

                $outbox[] = [
                    'phone' => $contact->phone,
                    'message' => $this->contactMessage($request->organisation->name, $frontDesk, $request->patient->username, $base.'/'.$token, $code),
                ];

                // If this contact has an account, they also see it inside the app.
                if (User::where('phone', $contact->phone)->exists()) {
                    AccessRequestNotice::create([
                        'access_request_id' => $request->id,
                        'recipient_type' => 'contact',
                        'emergency_contact_id' => $contact->id,
                        'channel' => NoticeChannel::App,
                        'sent_at' => now(),
                    ]);
                }
            }
        });

        // Texts go out only after the database change is safely saved, and one failure must not stop the rest.
        foreach ($outbox as $sms) {
            $this->sendSms($sms['phone'], $sms['message']);
        }

        Audit::record(AuditEvent::AccessEscalated, null, ['request_id' => $id, 'contacts' => count($outbox)]);
    }

    // ------------------------------------------------------------------ internals

    /**
     * The one place a request becomes a session. Locks the request, so two people approving at the
     * same moment open exactly one session (first wins, the second gets ACCESS_REQUEST_CLOSED).
     */
    private function approve(string $requestId, DecisionChannel $via, ?string $contactId = null, bool $byPatient = false): CareSession
    {
        $session = DB::transaction(function () use ($requestId, $via, $contactId, $byPatient): CareSession {
            $request = AccessRequest::lockForUpdate()->find($requestId);

            if ($request === null || ! $request->isPending() || $request->expires_at->isPast()) {
                throw new ApiException(ErrorCode::AccessRequestClosed);
            }

            $session = CareSession::create([
                'patient_id' => $request->patient_id,
                'organisation_id' => $request->organisation_id,
                'opened_by' => $request->requested_by,
                'access_method' => $byPatient ? 'card_qr' : 'emergency_contact',
                'access_level' => AccessLevel::Limited,   // card and emergency sessions read public records only
                'expires_at' => null,                      // until check-out (auto-closed after N days)
                'status' => CareSessionStatus::Active,
                'emergency_contact_id' => $contactId,
                'started_at' => now(),
            ]);

            $request->update([
                'status' => AccessRequestStatus::Approved,
                'decided_by_patient' => $byPatient,
                'decided_by_contact' => $contactId,
                'decided_via' => $via,
                'care_session_id' => $session->id,
                'decided_at' => now(),
            ]);

            $request->notices()->whereNull('used_at')->update(['used_at' => now()]);

            return $session;
        });

        Audit::record(AuditEvent::AccessApproved, null, [
            'request_id' => $requestId, 'session_id' => $session->id, 'via' => $via->value, 'contact_id' => $contactId,
        ]);
        Audit::record(AuditEvent::SessionOpened, null, ['session_id' => $session->id, 'patient_id' => $session->patient_id]);

        if (! $byPatient) {
            $patient = Patient::with('user')->find($session->patient_id);
            $org = Organisation::find($session->organisation_id);
            if ($patient?->user?->phone && $org) {
                $this->sendSms($patient->user->phone, "{$org->name} now has access to your OneHealth records, approved by your emergency contact. You can end it any time in the app.");
            }
        }

        return $session;
    }

    /** @param array{qrToken?: ?string, cardNumber?: ?string, dateOfBirth?: ?string} $input */
    private function findCard(array $input): HealthCard
    {
        $card = null;

        if (! empty($input['qrToken'])) {
            $card = HealthCard::where('qr_token', $input['qrToken'])->first();
        } elseif (! empty($input['cardNumber']) && ! empty($input['dateOfBirth'])) {
            $number = preg_replace('/\D/', '', (string) $input['cardNumber']) ?? '';
            $candidate = HealthCard::with('owner')->where('card_number', $number)->first();

            // Typing the number needs the patient's date of birth as well, so a seen number alone is useless.
            if ($candidate?->owner !== null && $candidate->owner->dob->toDateString() === $input['dateOfBirth']) {
                $card = $candidate;
            }
        }

        if ($card === null) {
            throw new ApiException(ErrorCode::CardNotFound);
        }

        return $card;
    }

    private function guardScanRate(Organisation $org, HealthCard $card): void
    {
        foreach ([
            ['scan-card:'.$card->id, (int) config('onehealth.access.max_requests_per_card_per_hour')],
            ['scan-org:'.$org->id, (int) config('onehealth.access.max_requests_per_org_per_hour')],
        ] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ApiException::tooManyAttempts(RateLimiter::availableIn($key));
            }
            RateLimiter::hit($key, 3600);
        }
    }

    private function ownedByPatient(Patient $patient, string $id): AccessRequest
    {
        $request = Str::isUuid($id) ? AccessRequest::where('patient_id', $patient->id)->find($id) : null;

        if ($request === null) {
            throw new ApiException(ErrorCode::AccessRequestNotFound);
        }

        $request = $this->refresh($request);

        if (! $request->isPending()) {
            throw new ApiException(ErrorCode::AccessRequestClosed);
        }

        return $request;
    }

    private function noticeByToken(string $token): AccessRequestNotice
    {
        $notice = AccessRequestNotice::where('token_hash', Hmac::lookup('approval-token', $token))->first();

        // Unknown, already used and expired links all look the same.
        if ($notice === null || $notice->used_at !== null) {
            throw new ApiException(ErrorCode::AccessRequestClosed);
        }

        return $notice;
    }

    /** @return array<string, mixed> */
    private function describe(AccessRequest $request): array
    {
        return [
            'requestId' => $request->id,
            'organisationName' => $request->organisation->name,
            'frontDeskNumbers' => $request->organisation->phones()->whereNotNull('verified_at')->pluck('phone')->all(),
            'patientName' => $request->patient->username,
            'requestedAt' => $request->created_at->toIso8601String(),
            'expiresAt' => $request->expires_at->toIso8601String(),
        ];
    }

    /** Public so controllers can show the same details to the patient and to in-app contacts. */
    public function summary(AccessRequest $request): array
    {
        return $this->describe($request->loadMissing(['organisation', 'patient']));
    }

    /** @param list<string> $frontDesk */
    private function contactMessage(string $org, array $frontDesk, string $patientName, string $link, string $code): string
    {
        $number = $frontDesk === [] ? '' : ' (front desk '.implode(' / ', $frontDesk).')';

        return "OneHealth: {$org}{$number} asks for access to {$patientName}'s medical records. "
            ."If you know {$patientName} is there, open {$link} to approve, or read this code {$code} to them ONLY on a call to or from that number. "
            .'If unsure, do not share it.';
    }

    private function newCode(): string
    {
        $length = (int) config('onehealth.access.code_length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    private function sendSms(string $phone, string $message): void
    {
        try {
            $this->sms->send($phone, $message);
        } catch (Throwable $e) {
            // A failed text must never undo or block the request. Log the cause (never the message: it holds a code).
            Log::error('Access SMS failed', ['error' => $e->getMessage()]);
        }
    }
}
