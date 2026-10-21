<?php

namespace Tests\Feature\Access;

use App\Models\CareSession;
use App\Models\HealthCard;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Scenario;
use Tests\TestCase;

class SessionAndRecordsTest extends TestCase
{
    use RefreshDatabase, Scenario;

    private const P = '/api/v1/provider';

    private const A = '/api/v1/access';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeScenario();
    }

    /** Scan and have the patient approve. Returns the session id. */
    private function openSession(): string
    {
        $id = $this->scan();

        return $this->actingAs($this->patientUser, 'web')->postJson(self::A."/requests/{$id}/approve")->json('data.session.id');
    }

    // ---- what the hospital can read ------------------------------------------------------------

    public function test_a_limited_session_sees_public_records_and_only_a_count_of_private_ones(): void
    {
        $this->record('Blood group', 'O positive', 'public');
        $this->record('Allergy', 'Penicillin', 'public');
        $this->record('Health condition', 'Secret condition', 'private');
        $sid = $this->openSession();

        $r = $this->actingAs($this->staff, 'web')->getJson(self::P."/sessions/{$sid}/essentials")->assertOk()
            ->assertJsonPath('data.privateRecordCount', 1)
            ->assertJsonPath('data.patient.fullName', $this->patient->fullname)
            ->assertJsonCount(2, 'data.records');

        $this->assertStringNotContainsString('Secret condition', json_encode($r->json()));
        $titles = collect($r->json('data.records'))->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['O positive', 'Penicillin'], $titles);
    }

    public function test_no_session_means_no_data(): void
    {
        $this->assertApiError(
            $this->actingAs($this->staff, 'web')->getJson(self::P.'/sessions/'.fake()->uuid().'/essentials'),
            404, 'SESSION_NOT_FOUND',
        );
    }

    public function test_another_hospital_cannot_use_my_session(): void
    {
        $sid = $this->openSession();
        $other = Organisation::factory()->create();

        $this->assertApiError($this->actingAs($other->user, 'web')->getJson(self::P."/sessions/{$sid}/essentials"), 404, 'SESSION_NOT_FOUND');
        $this->assertApiError($this->actingAs($other->user, 'web')->postJson(self::P."/sessions/{$sid}/checkout"), 404, 'SESSION_NOT_FOUND');
    }

    public function test_check_out_ends_access(): void
    {
        $sid = $this->openSession();

        $this->actingAs($this->staff, 'web')->postJson(self::P."/sessions/{$sid}/checkout")->assertOk();

        $this->assertApiError($this->actingAs($this->staff, 'web')->getJson(self::P."/sessions/{$sid}/essentials"), 409, 'SESSION_CLOSED');
        $this->assertSame('checked_out', CareSession::findOrFail($sid)->status->value);
    }

    public function test_the_patient_can_end_access_at_any_time(): void
    {
        $sid = $this->openSession();

        $this->actingAs($this->patientUser, 'web')->getJson(self::A.'/sessions?status=active')
            ->assertJsonCount(1, 'data.sessions')->assertJsonPath('data.sessions.0.organisationName', 'Perfect Medicals Inc.');

        $this->postJson(self::A."/sessions/{$sid}/revoke")->assertOk();

        $this->assertApiError($this->actingAs($this->staff, 'web')->getJson(self::P."/sessions/{$sid}/essentials"), 409, 'SESSION_CLOSED');
        $this->actingAs($this->patientUser, 'web')->getJson(self::A.'/sessions?status=active')->assertJsonCount(0, 'data.sessions');
    }

    public function test_a_patient_cannot_end_someone_elses_session(): void
    {
        $sid = $this->openSession();
        $other = User::factory()->create();
        Patient::factory()->create(['id' => $other->id]);

        $this->assertApiError($this->actingAs($other, 'web')->postJson(self::A."/sessions/{$sid}/revoke"), 404, 'SESSION_NOT_FOUND');
    }

    public function test_stale_sessions_close_themselves(): void
    {
        $sid = $this->openSession();

        $this->travel(4)->days();
        $this->artisan('onehealth:process-access')->assertSuccessful();

        $this->assertSame('expired', CareSession::findOrFail($sid)->status->value);
        $this->assertApiError($this->actingAs($this->staff, 'web')->getJson(self::P."/sessions/{$sid}/essentials"), 409, 'SESSION_CLOSED');
    }

    public function test_the_hospital_lists_only_its_own_open_sessions(): void
    {
        $this->openSession();
        $other = Organisation::factory()->create();

        $this->actingAs($this->staff, 'web')->getJson(self::P.'/sessions')->assertJsonCount(1, 'data.sessions');
        $this->actingAs($other->user, 'web')->getJson(self::P.'/sessions')->assertJsonCount(0, 'data.sessions');
    }

    // ---- writing ---------------------------------------------------------------------------------

    public function test_a_hospital_note_is_saved_private_and_the_hospital_cannot_publish_it(): void
    {
        $sid = $this->openSession();

        $r = $this->actingAs($this->staff, 'web')->postJson(self::P."/sessions/{$sid}/records", [
            'category' => 'clinical_report', 'type' => 'Consultation', 'title' => 'Road accident, admitted',
            'summary' => 'Free text written by the doctor.', 'visibility' => 'public',   // ignored
        ])->assertCreated()->assertJsonPath('data.record.visibility', 'private');

        $this->assertSame($this->staff->id, Record::findOrFail($r->json('data.record.id'))->created_by);

        // The hospital's own view still does not include it, and it has no route to change visibility.
        $this->getJson(self::P."/sessions/{$sid}/essentials")->assertJsonCount(0, 'data.records')->assertJsonPath('data.privateRecordCount', 1);
        $this->patchJson('/api/v1/records/'.$r->json('data.record.id').'/visibility', ['visibility' => 'public'])->assertStatus(403);
    }

    public function test_no_writing_after_check_out(): void
    {
        $sid = $this->openSession();
        $this->actingAs($this->staff, 'web')->postJson(self::P."/sessions/{$sid}/checkout")->assertOk();

        $this->assertApiError($this->postJson(self::P."/sessions/{$sid}/records", [
            'category' => 'clinical_report', 'type' => 'Consultation', 'title' => 'x',
        ]), 409, 'SESSION_CLOSED');
    }

    public function test_the_patient_owns_their_records_and_visibility(): void
    {
        $this->actingAs($this->patientUser, 'web');

        $r = $this->postJson('/api/v1/records', [
            'category' => 'medical_history', 'type' => 'Blood group', 'title' => 'O positive',
        ])->assertCreated()->assertJsonPath('data.record.visibility', 'private');   // private by default
        $id = $r->json('data.record.id');

        $this->patchJson("/api/v1/records/{$id}/visibility", ['visibility' => 'public'])->assertOk()
            ->assertJsonPath('data.record.visibility', 'public');

        $this->getJson('/api/v1/records?category=medical_history')->assertJsonCount(1, 'data.records');
        $this->getJson('/api/v1/records?category=vitals')->assertJsonCount(0, 'data.records');
    }

    public function test_free_text_is_accepted_but_structure_is_validated(): void
    {
        $this->actingAs($this->patientUser, 'web');

        // Any text is a valid blood group: nothing locks people to a list.
        $this->postJson('/api/v1/records', ['category' => 'medical_history', 'type' => 'Blood group', 'title' => 'not sure, maybe B-'])->assertCreated();
        $this->postJson('/api/v1/records', ['category' => 'nonsense', 'type' => 'x', 'title' => 'y'])->assertStatus(422);
        $this->postJson('/api/v1/records', ['category' => 'vitals', 'type' => 'Blood pressure', 'title' => '120/80', 'visibility' => 'maybe'])->assertStatus(422);
    }

    public function test_a_patient_cannot_change_someone_elses_record(): void
    {
        $other = User::factory()->create();
        $op = Patient::factory()->create(['id' => $other->id]);
        $rec = Record::create(['patient_id' => $op->id, 'category' => 'medical_history', 'type' => 'Allergy', 'title' => 'x', 'visibility' => 'private', 'created_by' => $op->id]);

        $this->assertApiError(
            $this->actingAs($this->patientUser, 'web')->patchJson("/api/v1/records/{$rec->id}/visibility", ['visibility' => 'public']),
            404, 'RECORD_NOT_FOUND',
        );
        $this->assertSame('private', $rec->fresh()->visibility->value);
    }

    // ---- cards -----------------------------------------------------------------------------------

    public function test_a_patient_activates_a_card_by_typing_its_number(): void
    {
        $fresh = app(\App\Services\Access\CardService::class)->issue();
        $other = User::factory()->create();
        $op = Patient::factory()->create(['id' => $other->id]);

        // Spaces and dashes are fine.
        $spaced = trim(chunk_split($fresh->card_number, 4, ' '));

        $this->actingAs($other, 'web')->postJson('/api/v1/cards/activate', ['cardNumber' => $spaced])->assertOk()
            ->assertJsonPath('data.card.status', 'activated')
            ->assertJsonPath('data.card.last4', substr($fresh->card_number, -4));

        $this->getJson('/api/v1/cards/me')->assertJsonPath('data.card.qrToken', $fresh->qr_token);
        $this->assertSame($op->id, HealthCard::findOrFail($fresh->id)->owner_id);
    }

    public function test_a_used_or_unknown_card_number_looks_the_same(): void
    {
        $this->actingAs($this->patientUser, 'web');
        $other = User::factory()->create();
        Patient::factory()->create(['id' => $other->id]);

        // My own card, already active: someone else typing it must not learn that it exists.
        $a = $this->assertApiError($this->actingAs($other, 'web')->postJson('/api/v1/cards/activate', ['cardNumber' => $this->card->card_number]), 404, 'CARD_NOT_FOUND');
        $b = $this->assertApiError($this->postJson('/api/v1/cards/activate', ['cardNumber' => '1111222233334444']), 404, 'CARD_NOT_FOUND');

        $this->assertSame($a['message'], $b['message']);
        $this->assertSame($this->patient->id, $this->card->fresh()->owner_id);
    }

    public function test_the_qr_token_is_never_the_card_number_and_cards_last_four_years(): void
    {
        $this->assertNotSame($this->card->card_number, $this->qr());
        $this->assertSame(16, strlen($this->card->card_number));
        $this->assertSame(now()->addYears(4)->toDateString(), $this->card->expires_at->toDateString());
    }

    public function test_activating_a_new_card_replaces_the_old_one(): void
    {
        $new = app(\App\Services\Access\CardService::class)->issue();

        $this->actingAs($this->patientUser, 'web')->postJson('/api/v1/cards/activate', ['cardNumber' => $new->card_number])->assertOk();

        $this->assertSame('deactivated', $this->card->fresh()->status->value);
        // The old QR no longer opens anything.
        $this->assertApiError(
            $this->actingAs($this->staff, 'web')->postJson(self::P.'/access-requests', ['qrToken' => $this->qr()]),
            409, 'CARD_NOT_ACTIVE',
        );
    }
}
