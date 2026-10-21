<?php

namespace Tests\Support;

use App\Enums\RecordCategory;
use App\Enums\UserType;
use App\Models\EmergencyContact;
use App\Models\HealthCard;
use App\Models\Organisation;
use App\Models\OrganisationStaff;
use App\Models\Patient;
use App\Models\ProviderPhone;
use App\Models\Record;
use App\Models\User;
use App\Services\Access\CardService;

/** Builds the people and card most access tests need. */
trait Scenario
{
    protected User $patientUser;

    protected Patient $patient;

    protected HealthCard $card;

    protected Organisation $org;

    protected User $staff;

    protected EmergencyContact $contact1;

    protected EmergencyContact $contact2;

    protected function makeScenario(): void
    {
        $this->patientUser = User::factory()->create(['phone' => '08000000001']);
        $this->patient = Patient::factory()->create([
            'id' => $this->patientUser->id, 'username' => 'Tunde Adeyemi', 'dob' => '1990-01-30',
        ]);

        $this->contact1 = EmergencyContact::create(['patient_id' => $this->patient->id, 'name' => 'Ada', 'relationship' => 'Sibling', 'phone' => '08000000002', 'priority' => 1]);
        $this->contact2 = EmergencyContact::create(['patient_id' => $this->patient->id, 'name' => 'Mama', 'relationship' => 'Parent', 'phone' => '08000000003', 'priority' => 2]);

        $cards = app(CardService::class);
        $this->card = $cards->issue();
        $cards->activate($this->patient, $this->card->card_number);
        $this->card->refresh();

        $this->org = Organisation::factory()->create(['name' => 'Perfect Medicals Inc.']);
        ProviderPhone::create(['organisation_id' => $this->org->id, 'phone' => '08031112222', 'label' => 'Front desk', 'verified_at' => now()]);

        $this->staff = User::factory()->create(['type' => UserType::OrgStaff, 'phone' => null, 'email' => 'front@pm.test']);
        OrganisationStaff::create(['id' => $this->staff->id, 'organisation_id' => $this->org->id, 'fullname' => 'Front Desk']);
    }

    protected function qr(): string
    {
        return $this->card->makeVisible('qr_token')->qr_token;
    }

    protected function record(string $type, string $title, string $visibility): Record
    {
        return Record::create([
            'patient_id' => $this->patient->id, 'category' => RecordCategory::MedicalHistory, 'type' => $type,
            'title' => $title, 'visibility' => $visibility, 'created_by' => $this->patient->id,
        ]);
    }

    /** Hospital scans the card; returns the request id. */
    protected function scan(?User $as = null): string
    {
        return $this->actingAs($as ?? $this->staff, 'web')
            ->postJson('/api/v1/provider/access-requests', ['qrToken' => $this->qr()])
            ->assertCreated()->json('data.requestId');
    }

    /** Pull the contact SMS sent for $phone: ['code' => ..., 'token' => ...]. */
    protected function contactSms(string $phone): array
    {
        $msgs = array_values(array_filter($this->sms->sent, fn ($s) => $s['phone'] === $phone));
        $text = end($msgs)['message'] ?? '';

        preg_match('/code (\d{6})/', $text, $code);
        preg_match('#/approve/([A-Za-z0-9]{40})#', $text, $token);

        return ['code' => $code[1] ?? null, 'token' => $token[1] ?? null, 'text' => $text];
    }
}
