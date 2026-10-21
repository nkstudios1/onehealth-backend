<?php

namespace Tests\Feature\Access;

use App\Enums\CardStatus;
use App\Models\AccessRequest;
use App\Models\CareSession;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Scenario;
use Tests\TestCase;

class AccessRequestTest extends TestCase
{
    use RefreshDatabase, Scenario;

    private const PROVIDER = '/api/v1/provider/access-requests';

    private const ACCESS = '/api/v1/access';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeScenario();
    }

    // ---- the scan --------------------------------------------------------------------------

    public function test_scan_creates_a_pending_request_and_opens_nothing(): void
    {
        $this->actingAs($this->staff, 'web')->postJson(self::PROVIDER, ['qrToken' => $this->qr()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.stage', 'patient')
            ->assertJsonPath('data.sessionId', null);

        $this->assertSame(0, CareSession::count());
        $this->assertSame(1, $this->sms->sentTo('08000000001'));       // the patient is told
        $this->assertSame(0, $this->sms->sentTo('08000000002'));       // contacts are NOT asked yet
    }

    public function test_scanning_twice_while_waiting_returns_the_same_request(): void
    {
        $a = $this->scan();
        $b = $this->scan();

        $this->assertSame($a, $b);
        $this->assertSame(1, AccessRequest::count());
        $this->assertSame(1, $this->sms->sentTo('08000000001'));
    }

    public function test_unknown_or_inactive_cards_are_refused(): void
    {
        $this->actingAs($this->staff, 'web');

        $this->assertApiError($this->postJson(self::PROVIDER, ['qrToken' => 'nope']), 404, 'CARD_NOT_FOUND');

        $this->card->update(['status' => CardStatus::Deactivated]);
        $this->assertApiError($this->postJson(self::PROVIDER, ['qrToken' => $this->qr()]), 409, 'CARD_NOT_ACTIVE');
    }

    public function test_an_expired_card_is_refused(): void
    {
        $this->card->update(['expires_at' => now()->subDay()]);

        $this->assertApiError(
            $this->actingAs($this->staff, 'web')->postJson(self::PROVIDER, ['qrToken' => $this->qr()]),
            409, 'CARD_NOT_ACTIVE',
        );
    }

    public function test_a_typed_card_number_needs_the_patients_date_of_birth(): void
    {
        $this->actingAs($this->staff, 'web');
        $number = $this->card->card_number;

        $this->assertApiError($this->postJson(self::PROVIDER, ['cardNumber' => $number, 'dateOfBirth' => '1999-09-09']), 404, 'CARD_NOT_FOUND');
        $this->postJson(self::PROVIDER, ['cardNumber' => $number])->assertStatus(422);
        $this->postJson(self::PROVIDER, ['cardNumber' => $number, 'dateOfBirth' => '1990-01-30'])->assertCreated();
    }

    public function test_an_unverified_organisation_cannot_scan(): void
    {
        $org = Organisation::factory()->unverified()->create();

        $this->assertApiError(
            $this->actingAs($org->user, 'web')->postJson(self::PROVIDER, ['qrToken' => $this->qr()]),
            403, 'ORGANISATION_NOT_VERIFIED',
        );
        $this->assertSame(0, $this->sms->sentTo('08000000001'));
    }

    public function test_only_hospital_accounts_can_scan_and_only_patients_can_answer(): void
    {
        $this->assertApiError($this->actingAs($this->patientUser, 'web')->postJson(self::PROVIDER, ['qrToken' => $this->qr()]), 403, 'FORBIDDEN');

        $id = $this->scan();
        $this->assertApiError($this->actingAs($this->staff, 'web')->postJson(self::ACCESS."/requests/{$id}/approve"), 403, 'FORBIDDEN');
    }

    public function test_scans_are_rate_limited_per_card(): void
    {
        $this->actingAs($this->staff, 'web');
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        for ($i = 0; $i < 5; $i++) {
            $id = $this->postJson(self::PROVIDER, ['qrToken' => $this->qr()])->assertCreated()->json('data.requestId');
            AccessRequest::whereKey($id)->update(['status' => 'denied']);
        }

        $this->assertApiError($this->postJson(self::PROVIDER, ['qrToken' => $this->qr()]), 429, 'TOO_MANY_ATTEMPTS');
    }

    // ---- the patient answers -----------------------------------------------------------------

    public function test_patient_sees_and_approves_a_request(): void
    {
        $id = $this->scan();

        $this->actingAs($this->patientUser, 'web')->getJson(self::ACCESS.'/requests/pending')->assertOk()
            ->assertJsonPath('data.requests.0.requestId', $id)
            ->assertJsonPath('data.requests.0.organisationName', 'Perfect Medicals Inc.')
            ->assertJsonPath('data.requests.0.frontDeskNumbers.0', '08031112222');

        $r = $this->postJson(self::ACCESS."/requests/{$id}/approve")->assertOk()
            ->assertJsonPath('data.session.accessLevel', 'limited')
            ->assertJsonPath('data.session.status', 'active');

        $session = CareSession::findOrFail($r->json('data.session.id'));
        $this->assertNull($session->expires_at);                       // until check-out
        $this->assertSame($this->org->id, $session->organisation_id);

        $this->actingAs($this->staff, 'web')->getJson(self::PROVIDER."/{$id}")
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.sessionId', $session->id);
    }

    public function test_patient_can_deny(): void
    {
        $id = $this->scan();

        $this->actingAs($this->patientUser, 'web')->postJson(self::ACCESS."/requests/{$id}/deny")->assertOk();

        $this->actingAs($this->staff, 'web')->getJson(self::PROVIDER."/{$id}")->assertJsonPath('data.status', 'denied');
        $this->assertSame(0, CareSession::count());
        $this->assertApiError($this->actingAs($this->patientUser, 'web')->postJson(self::ACCESS."/requests/{$id}/approve"), 409, 'ACCESS_REQUEST_CLOSED');
    }

    public function test_another_patient_cannot_answer_my_request(): void
    {
        $id = $this->scan();

        $other = User::factory()->create();
        Patient::factory()->create(['id' => $other->id]);

        $this->assertApiError($this->actingAs($other, 'web')->postJson(self::ACCESS."/requests/{$id}/approve"), 404, 'ACCESS_REQUEST_NOT_FOUND');
        $this->assertSame(0, CareSession::count());
    }

    public function test_another_hospital_cannot_read_my_request(): void
    {
        $id = $this->scan();
        $other = Organisation::factory()->create();

        $this->assertApiError($this->actingAs($other->user, 'web')->getJson(self::PROVIDER."/{$id}"), 404, 'ACCESS_REQUEST_NOT_FOUND');
    }

    public function test_a_request_expires(): void
    {
        $id = $this->scan();

        $this->travel(31)->minutes();

        $this->actingAs($this->staff, 'web')->getJson(self::PROVIDER."/{$id}")->assertJsonPath('data.status', 'expired');
        $this->assertApiError($this->actingAs($this->patientUser, 'web')->postJson(self::ACCESS."/requests/{$id}/approve"), 409, 'ACCESS_REQUEST_CLOSED');
    }

    // ---- escalation to emergency contacts ------------------------------------------------------

    public function test_unanswered_request_goes_to_every_contact_after_the_wait(): void
    {
        $id = $this->scan();

        $this->travel(60)->seconds();
        $this->actingAs($this->staff, 'web')->getJson(self::PROVIDER."/{$id}")->assertJsonPath('data.stage', 'patient');
        $this->assertSame(0, $this->sms->sentTo('08000000002'));

        $this->travel(61)->seconds();
        $this->actingAs($this->staff, 'web')->getJson(self::PROVIDER."/{$id}")->assertJsonPath('data.stage', 'contacts');

        foreach (['08000000002', '08000000003'] as $phone) {
            $sms = $this->contactSms($phone);
            $this->assertSame(1, $this->sms->sentTo($phone));
            $this->assertMatchesRegularExpression('/^\d{6}$/', $sms['code']);
            $this->assertStringContainsString('Perfect Medicals Inc.', $sms['text']);
            $this->assertStringContainsString('08031112222', $sms['text']);   // the hospital's registered number
            $this->assertStringContainsString('Tunde Adeyemi', $sms['text']);
        }

        // Each contact gets a different code, and a different link.
        $this->assertNotSame($this->contactSms('08000000002')['code'], $this->contactSms('08000000003')['code']);
    }

    public function test_escalation_happens_only_once(): void
    {
        $id = $this->scan();
        $this->travel(130)->seconds();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->staff, 'web')->getJson(self::PROVIDER."/{$id}");
        }
        $this->artisan('onehealth:process-access')->assertSuccessful();

        $this->assertSame(1, $this->sms->sentTo('08000000003'));
    }

    public function test_the_scheduler_escalates_without_anyone_polling(): void
    {
        $id = $this->scan();
        $this->travel(130)->seconds();

        $this->artisan('onehealth:process-access')->assertSuccessful();

        $this->assertSame('contacts', AccessRequest::findOrFail($id)->stage->value);
        $this->assertSame(1, $this->sms->sentTo('08000000003'));
    }

    public function test_contact_code_read_to_the_hospital_opens_a_session(): void
    {
        $id = $this->scan();
        $this->travel(130)->seconds();
        $this->actingAs($this->staff, 'web')->getJson(self::PROVIDER."/{$id}");
        $code = $this->contactSms('08000000003')['code'];

        $r = $this->postJson(self::PROVIDER."/{$id}/code", ['code' => $code])->assertOk();

        $session = CareSession::findOrFail($r->json('data.sessionId'));
        $this->assertSame('emergency_contact', $session->access_method);
        $this->assertSame($this->contact2->id, $session->emergency_contact_id);
        $this->assertSame('limited', $session->access_level->value);
        $this->assertNull($session->expires_at);
        $this->assertSame(2, $this->sms->sentTo('08000000001'));       // patient told it opened (plus the first request text)
    }

    public function test_a_code_for_another_request_does_not_work(): void
    {
        $id = $this->scan();
        $this->travel(130)->seconds();
        $this->actingAs($this->staff, 'web')->getJson(self::PROVIDER."/{$id}");

        $this->assertApiError($this->postJson(self::PROVIDER."/{$id}/code", ['code' => '000000']), 422, 'APPROVAL_CODE_INVALID');
    }

    public function test_five_wrong_codes_kill_the_request_even_for_the_right_code(): void
    {
        $id = $this->scan();
        $this->travel(130)->seconds();
        $this->actingAs($this->staff, 'web')->getJson(self::PROVIDER."/{$id}");
        $code = $this->contactSms('08000000003')['code'];
        $wrong = $code === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 5; $i++) {
            $this->assertApiError($this->postJson(self::PROVIDER."/{$id}/code", ['code' => $wrong]), 422, 'APPROVAL_CODE_INVALID');
        }

        $this->assertApiError($this->postJson(self::PROVIDER."/{$id}/code", ['code' => $code]), 409, 'ACCESS_REQUEST_CLOSED');
        $this->assertSame(0, CareSession::count());
    }

    public function test_the_code_is_refused_before_the_contacts_have_been_asked(): void
    {
        $id = $this->scan();

        $this->assertApiError($this->postJson(self::PROVIDER."/{$id}/code", ['code' => '123456']), 422, 'APPROVAL_CODE_INVALID');
    }

    public function test_the_sms_link_only_describes_until_the_contact_presses_approve(): void
    {
        $id = $this->scan();
        $this->travel(130)->seconds();
        $this->actingAs($this->staff, 'web')->getJson(self::PROVIDER."/{$id}");
        $token = $this->contactSms('08000000003')['token'];

        // A scanner (or a curious tap) that merely opens the link must not approve anything.
        $this->getJson(self::ACCESS."/approvals/{$token}")->assertOk()
            ->assertJsonPath('data.organisationName', 'Perfect Medicals Inc.')
            ->assertJsonPath('data.patientName', 'Tunde Adeyemi');
        $this->assertSame('pending', AccessRequest::findOrFail($id)->status->value);
        $this->assertSame(0, CareSession::count());

        $this->postJson(self::ACCESS."/approvals/{$token}/approve")->assertOk();
        $this->assertSame(1, CareSession::count());
        $this->assertSame('contact_link', AccessRequest::findOrFail($id)->decided_via->value);

        // Single use.
        $this->assertApiError($this->postJson(self::ACCESS."/approvals/{$token}/approve"), 409, 'ACCESS_REQUEST_CLOSED');
        $this->assertSame(1, CareSession::count());
    }

    public function test_first_approval_wins_and_the_other_links_die(): void
    {
        $id = $this->scan();
        $this->travel(130)->seconds();
        $this->actingAs($this->staff, 'web')->getJson(self::PROVIDER."/{$id}");
        $t1 = $this->contactSms('08000000002')['token'];
        $t2 = $this->contactSms('08000000003')['token'];

        $this->postJson(self::ACCESS."/approvals/{$t1}/approve")->assertOk();

        $this->assertApiError($this->postJson(self::ACCESS."/approvals/{$t2}/approve"), 409, 'ACCESS_REQUEST_CLOSED');
        $this->assertApiError($this->getJson(self::ACCESS."/approvals/{$t2}"), 409, 'ACCESS_REQUEST_CLOSED');
        $this->assertSame(1, CareSession::count());
    }

    public function test_a_link_does_not_work_before_the_contacts_stage_or_when_made_up(): void
    {
        $this->assertApiError($this->getJson(self::ACCESS.'/approvals/'.str_repeat('a', 40)), 409, 'ACCESS_REQUEST_CLOSED');
        $this->assertApiError($this->postJson(self::ACCESS.'/approvals/'.str_repeat('a', 40).'/approve'), 409, 'ACCESS_REQUEST_CLOSED');
    }

    public function test_a_contact_with_an_account_approves_inside_the_app(): void
    {
        $contactUser = User::factory()->create(['phone' => '08000000002']);   // matches contact 1
        $id = $this->scan();

        // Not visible until the patient has had their chance.
        $this->actingAs($contactUser, 'web')->getJson(self::ACCESS.'/contact-requests')->assertJsonCount(0, 'data.requests');

        $this->travel(130)->seconds();
        $this->actingAs($contactUser, 'web')->getJson(self::ACCESS.'/contact-requests')
            ->assertJsonCount(1, 'data.requests')->assertJsonPath('data.requests.0.requestId', $id);

        $this->postJson(self::ACCESS."/contact-requests/{$id}/approve")->assertOk();

        $this->assertSame('contact_app', AccessRequest::findOrFail($id)->decided_via->value);
        $this->assertSame($this->contact1->id, CareSession::firstOrFail()->emergency_contact_id);
    }

    public function test_a_stranger_cannot_approve_through_the_app(): void
    {
        $stranger = User::factory()->create(['phone' => '08099999999']);
        $id = $this->scan();
        $this->travel(130)->seconds();

        $this->assertApiError($this->actingAs($stranger, 'web')->postJson(self::ACCESS."/contact-requests/{$id}/approve"), 404, 'ACCESS_REQUEST_NOT_FOUND');
        $this->assertSame(0, CareSession::count());
    }

    public function test_the_patient_can_still_approve_after_contacts_were_asked(): void
    {
        $id = $this->scan();
        $this->travel(130)->seconds();
        $this->actingAs($this->staff, 'web')->getJson(self::PROVIDER."/{$id}");

        $this->actingAs($this->patientUser, 'web')->postJson(self::ACCESS."/requests/{$id}/approve")->assertOk();

        $this->assertSame(1, CareSession::count());
        $this->assertTrue((bool) AccessRequest::findOrFail($id)->decided_by_patient);
    }

    public function test_a_failed_text_message_does_not_break_the_flow(): void
    {
        $this->sms->fail = true;

        $this->actingAs($this->staff, 'web')->postJson(self::PROVIDER, ['qrToken' => $this->qr()])->assertCreated();
        $this->assertSame(1, AccessRequest::count());
    }
}
