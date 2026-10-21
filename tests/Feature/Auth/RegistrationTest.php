<?php

namespace Tests\Feature\Auth;

use App\Models\EmergencyContact;
use App\Models\OtpCode;
use App\Models\Patient;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '08031234567';

    private const NIN = '12345678901'; // last digit 1 -> "Tunde Babatunde Adeyemi"

    private function start(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::BASE.'/register/start', $over + ['phone' => self::PHONE, 'nin' => self::NIN, 'consent' => true]);
    }

    private function contacts(): array
    {
        return [
            ['name' => 'Ada Obi', 'relationship' => 'Sibling', 'phone' => '08011112222'],
            ['name' => 'Ben Obi', 'relationship' => 'Cousin', 'phone' => '08033334444', 'email' => 'ben@example.com'],
        ];
    }

    public function test_full_signup_creates_a_patient_and_cleans_up(): void
    {
        $r = $this->start();
        $r->assertOk()->assertJsonPath('data.identity.fullName', 'Tunde Babatunde Adeyemi')
            ->assertJsonPath('data.identity.gender', 'Male');
        $id = $r->json('data.registrationId');

        $this->postJson(self::BASE.'/register/confirm-identity', ['registrationId' => $id])
            ->assertOk()->assertJsonPath('data.resendAfterSeconds', 300);
        $this->assertSame(1, $this->sms->sentTo(self::PHONE));

        $this->postJson(self::BASE.'/register/verify-otp', ['registrationId' => $id, 'code' => '1234'])->assertOk();
        $this->postJson(self::BASE.'/register/emergency-contacts', ['registrationId' => $id, 'contacts' => $this->contacts()])->assertOk();

        $done = $this->postJson(self::BASE.'/register/complete', ['registrationId' => $id, 'password' => 'Secret123']);
        $done->assertCreated()->assertJsonPath('data.username', 'Tunde Adeyemi');

        $personalId = $done->json('data.personalId');
        $this->assertMatchesRegularExpression('/^[1-9]\d{9}$/', $personalId);

        $patient = Patient::where('personal_id', $personalId)->firstOrFail();
        $this->assertSame(self::PHONE, $patient->user->phone);
        $this->assertSame('8901', $patient->nin_last4);
        $this->assertNotSame(self::NIN, $patient->nin_hash);          // raw NIN is never stored
        $this->assertStringNotContainsString(self::NIN, json_encode($patient->getAttributes()));
        $this->assertSame(2, EmergencyContact::count());
        $this->assertSame([1, 2], EmergencyContact::orderBy('priority')->pluck('priority')->all());

        $this->assertSame(0, Registration::count());                  // PII row removed
        $this->assertSame(0, OtpCode::count());
        $this->assertGuest('web');                                    // no auto-login
    }

    public function test_requires_consent_and_valid_formats(): void
    {
        $this->start(['consent' => false])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->start(['nin' => '123'])->assertStatus(422);
        $this->start(['phone' => '12345'])->assertStatus(422);
    }

    public function test_unknown_nin(): void
    {
        $this->assertApiError($this->start(['nin' => '00000000000']), 404, 'NIN_NOT_FOUND');
    }

    public function test_nin_or_phone_already_registered(): void
    {
        $user = User::factory()->create(['phone' => self::PHONE]);
        $this->assertApiError($this->start(), 409, 'PHONE_ALREADY_REGISTERED');

        $user->delete();
        Patient::factory()->create(['nin_hash' => \App\Support\Hmac::nin(self::NIN)]);
        $this->assertApiError($this->start(), 409, 'NIN_ALREADY_REGISTERED');
    }

    public function test_steps_cannot_be_skipped(): void
    {
        $id = $this->start()->json('data.registrationId');

        $this->assertApiError($this->postJson(self::BASE.'/register/verify-otp', ['registrationId' => $id, 'code' => '1234']), 409, 'REGISTRATION_STEP_INVALID');
        $this->assertApiError($this->postJson(self::BASE.'/register/emergency-contacts', ['registrationId' => $id, 'contacts' => $this->contacts()]), 409, 'REGISTRATION_STEP_INVALID');
        $this->assertApiError($this->postJson(self::BASE.'/register/complete', ['registrationId' => $id, 'password' => 'Secret123']), 409, 'REGISTRATION_STEP_INVALID');
        $this->assertSame(0, User::count());
    }

    public function test_unknown_or_malformed_registration_id(): void
    {
        $this->assertApiError($this->postJson(self::BASE.'/register/confirm-identity', ['registrationId' => 'nope']), 404, 'REGISTRATION_NOT_FOUND');
        $this->assertApiError($this->postJson(self::BASE.'/register/confirm-identity', ['registrationId' => '9f1c2b0e-3d4a-4e5f-8a6b-7c8d9e0f1a2b']), 404, 'REGISTRATION_NOT_FOUND');
    }

    public function test_wrong_code_then_lockout_kills_the_code(): void
    {
        $id = $this->start()->json('data.registrationId');
        $this->postJson(self::BASE.'/register/confirm-identity', ['registrationId' => $id])->assertOk();

        $this->assertApiError($this->postJson(self::BASE.'/register/verify-otp', ['registrationId' => $id, 'code' => '0000']), 422, 'OTP_INVALID');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson(self::BASE.'/register/verify-otp', ['registrationId' => $id, 'code' => '0000']);
        }

        // 5 wrong guesses used up: even the RIGHT code is now refused.
        $this->assertApiError($this->postJson(self::BASE.'/register/verify-otp', ['registrationId' => $id, 'code' => '1234']), 422, 'OTP_EXPIRED');
    }

    public function test_resend_respects_cooldown_then_works(): void
    {
        $id = $this->start()->json('data.registrationId');
        $this->postJson(self::BASE.'/register/confirm-identity', ['registrationId' => $id])->assertOk();

        $body = $this->assertApiError($this->postJson(self::BASE.'/register/resend-otp', ['registrationId' => $id]), 429, 'TOO_MANY_ATTEMPTS');
        $this->assertGreaterThan(0, $body['meta']['retryAfter']);

        $this->travel(301)->seconds();
        $this->postJson(self::BASE.'/register/resend-otp', ['registrationId' => $id])->assertOk();
        $this->assertSame(2, $this->sms->sentTo(self::PHONE));
    }

    public function test_sms_failure_is_reported_safely(): void
    {
        $id = $this->start()->json('data.registrationId');
        $this->sms->fail = true;

        $body = $this->assertApiError($this->postJson(self::BASE.'/register/confirm-identity', ['registrationId' => $id]), 503, 'OTP_DELIVERY_FAILED');
        $this->assertStringNotContainsString('provider down', json_encode($body));
        $this->assertSame(0, OtpCode::whereNull('consumed_at')->count());
    }

    public function test_contacts_validation(): void
    {
        $id = $this->start()->json('data.registrationId');
        $this->postJson(self::BASE.'/register/confirm-identity', ['registrationId' => $id]);
        $this->postJson(self::BASE.'/register/verify-otp', ['registrationId' => $id, 'code' => '1234'])->assertOk();

        $one = ['name' => 'A', 'relationship' => 'Friend', 'phone' => '08011112222'];

        $this->postJson(self::BASE.'/register/emergency-contacts', ['registrationId' => $id, 'contacts' => []])->assertStatus(422);
        $this->postJson(self::BASE.'/register/emergency-contacts', ['registrationId' => $id, 'contacts' => [$one, $one]])->assertStatus(422); // duplicate phone
        $four = array_map(fn ($i) => ['name' => 'A', 'relationship' => 'Friend', 'phone' => '0801111222'.$i], range(0, 3));
        $this->postJson(self::BASE.'/register/emergency-contacts', ['registrationId' => $id, 'contacts' => $four])->assertStatus(422);
    }

    public function test_weak_password_rejected_at_complete(): void
    {
        $id = $this->start()->json('data.registrationId');
        $this->postJson(self::BASE.'/register/confirm-identity', ['registrationId' => $id]);
        $this->postJson(self::BASE.'/register/verify-otp', ['registrationId' => $id, 'code' => '1234']);
        $this->postJson(self::BASE.'/register/emergency-contacts', ['registrationId' => $id, 'contacts' => $this->contacts()]);

        foreach (['short1', 'onlyletters', '12345678'] as $weak) {
            $this->postJson(self::BASE.'/register/complete', ['registrationId' => $id, 'password' => $weak])->assertStatus(422);
        }
        $this->assertSame(0, User::count());
    }

    public function test_complete_cannot_be_replayed(): void
    {
        $id = $this->start()->json('data.registrationId');
        $this->postJson(self::BASE.'/register/confirm-identity', ['registrationId' => $id]);
        $this->postJson(self::BASE.'/register/verify-otp', ['registrationId' => $id, 'code' => '1234']);
        $this->postJson(self::BASE.'/register/emergency-contacts', ['registrationId' => $id, 'contacts' => $this->contacts()]);
        $this->postJson(self::BASE.'/register/complete', ['registrationId' => $id, 'password' => 'Secret123'])->assertCreated();

        $this->assertApiError($this->postJson(self::BASE.'/register/complete', ['registrationId' => $id, 'password' => 'Secret123']), 404, 'REGISTRATION_NOT_FOUND');
        $this->assertSame(1, User::count());
    }
}
