<?php

namespace App\Services\Auth;

use App\Contracts\NinVerifier;
use App\Enums\AccountStatus;
use App\Enums\AuditEvent;
use App\Enums\ErrorCode;
use App\Enums\OtpPurpose;
use App\Enums\RegistrationStep;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\EmergencyContact;
use App\Models\OtpCode;
use App\Models\Patient;
use App\Models\Registration;
use App\Models\User;
use App\Support\Audit;
use App\Support\Hmac;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The patient sign-up flow, one method per step:
 *
 *   start -> confirmIdentity -> (resendOtp) -> verifyOtp -> saveContacts -> complete
 *
 * Each step checks the registration is at the right stage, so a client can't skip
 * ahead. Controllers only call these methods; all the rules live here.
 */
class RegistrationService
{
    public function __construct(
        private readonly NinVerifier $nin,
        private readonly OtpService $otp,
    ) {}

    /**
     * Step 1: look the NIN up and open a sign-up. Nothing is created that can log in yet.
     *
     * @return array{registration: Registration}
     */
    public function start(string $phone, string $nin, string $ip): array
    {
        if (User::where('phone', $phone)->exists()) {
            throw new ApiException(ErrorCode::PhoneAlreadyRegistered);
        }

        $ninHash = Hmac::nin($nin);

        if (Patient::where('nin_hash', $ninHash)->exists()) {
            throw new ApiException(ErrorCode::NinAlreadyRegistered);
        }

        // Throws NIN_NOT_FOUND / NIN_SERVICE_UNAVAILABLE. The raw NIN goes no further than this call.
        $record = $this->nin->lookup($nin);

        $registration = DB::transaction(function () use ($phone, $ninHash, $nin, $record, $ip) {
            // One open sign-up per phone number: starting again replaces the old attempt.
            $old = Registration::where('phone', $phone)->pluck('id');
            if ($old->isNotEmpty()) {
                OtpCode::whereIn('subject_id', $old)->where('purpose', OtpPurpose::SignupPhone->value)->delete();
                Registration::whereIn('id', $old)->delete();
            }

            return Registration::create([
                'phone' => $phone,
                'nin_hash' => $ninHash,
                'nin_last4' => substr($nin, -4),
                'full_name' => $record->fullName,
                'dob' => $record->dateOfBirth,
                'gender' => $record->gender,
                'photo_url' => $record->photoUrl,
                'step' => RegistrationStep::Started,
                'consented_at' => now(),
                'consent_version' => (string) config('onehealth.consent_version'),
                'expires_at' => $this->newExpiry(),
                'ip_address' => $ip,
            ]);
        });

        Audit::record(AuditEvent::RegistrationStarted, null, ['registration_id' => $registration->id]);

        return ['registration' => $registration];
    }

    /** Step 2: "Yes, this is me". Texts the code. Returns seconds until a resend is allowed. */
    public function confirmIdentity(string $registrationId): int
    {
        $registration = $this->find($registrationId);

        // Pressing the button twice must not skip ahead or break; anything past Started is fine to repeat.
        $this->requireStep($registration, RegistrationStep::Started);

        if ($registration->step->isAtLeast(RegistrationStep::PhoneVerified)) {
            throw new ApiException(ErrorCode::RegistrationStepInvalid);
        }

        $this->otp->sendBySms(OtpPurpose::SignupPhone, $registration->id, $registration->phone);

        if ($registration->step === RegistrationStep::Started) {
            $registration->step = RegistrationStep::IdentityConfirmed;
        }
        $registration->otp_sent_at = now();
        $registration->expires_at = $this->newExpiry();
        $registration->save();

        Audit::record(AuditEvent::RegistrationIdentityConfirmed, null, ['registration_id' => $registration->id]);

        return $this->otp->resendAfterSeconds();
    }

    /** "Resend code". Enforces the cooldown server-side (the app's timer is only cosmetic). */
    public function resendOtp(string $registrationId): int
    {
        $registration = $this->find($registrationId);
        $this->requireStep($registration, RegistrationStep::IdentityConfirmed);

        if ($registration->step->isAtLeast(RegistrationStep::PhoneVerified)) {
            throw new ApiException(ErrorCode::RegistrationStepInvalid);
        }

        $this->enforceResendCooldown($registration);

        $this->otp->sendBySms(OtpPurpose::SignupPhone, $registration->id, $registration->phone);

        $registration->otp_sent_at = now();
        $registration->expires_at = $this->newExpiry();
        $registration->save();

        return $this->otp->resendAfterSeconds();
    }

    /** Step 3: check the code from the SMS. */
    public function verifyOtp(string $registrationId, string $code): void
    {
        $registration = $this->find($registrationId);
        $this->requireStep($registration, RegistrationStep::IdentityConfirmed);

        if ($registration->step->isAtLeast(RegistrationStep::PhoneVerified)) {
            throw new ApiException(ErrorCode::RegistrationStepInvalid);
        }

        $this->otp->verify(OtpPurpose::SignupPhone, $registration->id, $code);

        $registration->step = RegistrationStep::PhoneVerified;
        $registration->expires_at = $this->newExpiry();
        $registration->save();

        Audit::record(AuditEvent::RegistrationPhoneVerified, null, ['registration_id' => $registration->id]);
    }

    /**
     * Step 4: keep the emergency contacts (1 to 3, already validated by the request class).
     * They are saved to the account at the end, in one transaction with it.
     *
     * @param  list<array{name:string,relationship:string,phone:string,email?:?string}>  $contacts
     */
    public function saveContacts(string $registrationId, array $contacts): void
    {
        $registration = $this->find($registrationId);
        $this->requireStep($registration, RegistrationStep::PhoneVerified);

        $registration->contacts = array_values(array_map(fn (array $c) => [
            'name' => $c['name'],
            'relationship' => $c['relationship'],
            'phone' => $c['phone'],
            'email' => $c['email'] ?? null,
        ], $contacts));
        $registration->step = RegistrationStep::ContactsSaved;
        $registration->expires_at = $this->newExpiry();
        $registration->save();
    }

    /**
     * Step 5: create the account. Returns the username and personal ID for the success screen.
     * Does NOT log the person in: they log in with their new password, like everywhere else.
     *
     * @return array{username: string, personalId: string}
     */
    public function complete(string $registrationId, string $password): array
    {
        try {
            $result = DB::transaction(function () use ($registrationId, $password) {
                // Lock the row so two requests can't both create the account.
                $registration = Registration::query()->lockForUpdate()->find($registrationId);

                if ($registration === null || $registration->isExpired()) {
                    throw new ApiException(ErrorCode::RegistrationNotFound);
                }
                if ($registration->step !== RegistrationStep::ContactsSaved) {
                    throw new ApiException(ErrorCode::RegistrationStepInvalid);
                }

                $user = User::create([
                    'type' => UserType::Patient,
                    'phone' => $registration->phone,
                    'password' => $password,
                    'status' => AccountStatus::Active,
                ]);

                $patient = Patient::create([
                    'id' => $user->id,
                    'nin_hash' => $registration->nin_hash,
                    'nin_last4' => $registration->nin_last4,
                    'fullname' => $registration->full_name,
                    'username' => self::displayName($registration->full_name),
                    'dob' => $registration->dob,
                    'gender' => $registration->gender,
                    'personal_id' => $this->newPersonalId(),
                    'consented_at' => $registration->consented_at,
                    'consent_version' => $registration->consent_version,
                ]);

                foreach (($registration->contacts ?? []) as $i => $c) {
                    EmergencyContact::create([
                        'patient_id' => $patient->id,
                        'name' => $c['name'],
                        'relationship' => $c['relationship'],
                        'phone' => $c['phone'],
                        'email' => $c['email'] ?? null,
                        'priority' => $i + 1,
                    ]);
                }

                // The sign-up held personal data only until now. Remove it and its codes.
                OtpCode::where('purpose', OtpPurpose::SignupPhone->value)->where('subject_id', $registration->id)->delete();
                $registration->delete();

                return ['username' => $patient->username, 'personalId' => $patient->personal_id, 'userId' => $user->id];
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (QueryException $e) {
            // The phone or NIN was taken between the first step and now (a race), or similar.
            Log::warning('Registration conflict on complete', ['error' => $e->getMessage()]);
            Audit::record(AuditEvent::RegistrationFailed, null, ['registration_id' => $registrationId, 'reason' => 'db_conflict']);

            throw new ApiException(ErrorCode::RegistrationFailed, previous: $e);
        } catch (Throwable $e) {
            Audit::record(AuditEvent::RegistrationFailed, null, ['registration_id' => $registrationId, 'reason' => 'unexpected']);

            throw $e;
        }

        Audit::record(AuditEvent::RegistrationCompleted, $result['userId']);

        return ['username' => $result['username'], 'personalId' => $result['personalId']];
    }

    /** First and last name only ("Adaeze Chinwe Okafor" becomes "Adaeze Okafor"), title-cased. */
    public static function displayName(string $fullName): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/u', trim($fullName)) ?: []));

        if ($parts === []) {
            return '';
        }

        $pick = count($parts) === 1 ? [$parts[0]] : [$parts[0], $parts[count($parts) - 1]];

        return implode(' ', array_map(fn (string $p) => mb_convert_case($p, MB_CASE_TITLE, 'UTF-8'), $pick));
    }

    /** A random 10-digit ID that does not start with 0 (so it can't be mistaken for a phone number). */
    private function newPersonalId(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $id = (string) random_int(1, 9).str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT);

            if (! Patient::where('personal_id', $id)->exists()) {
                return $id;
            }
        }

        throw new ApiException(ErrorCode::RegistrationFailed);
    }

    private function find(string $id): Registration
    {
        // A malformed id must look the same as an unknown one.
        $registration = Str::isUuid($id) ? Registration::find($id) : null;

        if ($registration === null || $registration->isExpired()) {
            throw new ApiException(ErrorCode::RegistrationNotFound);
        }

        return $registration;
    }

    private function requireStep(Registration $registration, RegistrationStep $minimum): void
    {
        if (! $registration->step->isAtLeast($minimum)) {
            throw new ApiException(ErrorCode::RegistrationStepInvalid);
        }
    }

    private function enforceResendCooldown(Registration $registration): void
    {
        if ($registration->otp_sent_at === null) {
            return;
        }

        $wait = (int) config('onehealth.otp.resend_after_seconds');
        $elapsed = (int) $registration->otp_sent_at->diffInSeconds(now(), true);

        if ($elapsed < $wait) {
            throw ApiException::tooManyAttempts($wait - $elapsed);
        }
    }

    private function newExpiry()
    {
        return now()->addMinutes((int) config('onehealth.registration.ttl_minutes'));
    }
}
