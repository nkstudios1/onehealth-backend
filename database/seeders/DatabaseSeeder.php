<?php

namespace Database\Seeders;

use App\Enums\RecordCategory;
use App\Enums\UserType;
use App\Models\EmergencyContact;
use App\Models\Organisation;
use App\Models\OrganisationStaff;
use App\Models\Patient;
use App\Models\ProviderPhone;
use App\Models\Record;
use App\Models\User;
use App\Services\Access\CardService;
use Illuminate\Database\Seeder;

/**
 * Local development data only. Refuses to run in production (it creates known passwords).
 *
 *   Patient   08000000001  or personal ID 4829175036   password Password1
 *             card number printed below, activated, with 2 emergency contacts and public essentials
 *   Hospital  front@perfectmedicals.test               password Password1   (Perfect Medicals Inc., verified)
 *   Contact   08000000002 is a contact WITH an account (sees requests in the app)
 *             08000000003 is a contact WITHOUT an account (SMS link / code)
 */
class DatabaseSeeder extends Seeder
{
    public function run(CardService $cards): void
    {
        if (app()->isProduction()) {
            $this->command?->error('The seeder creates known passwords. It will not run in production.');

            return;
        }

        // Safe to run on every deploy: if the demo data is already there, do nothing.
        if (User::query()->exists()) {
            $this->command?->info('Users already exist. Seeder skipped.');

            return;
        }

        // ---- patient ------------------------------------------------------------------------
        $user = User::factory()->create(['phone' => '08000000001']);
        $patient = Patient::factory()->create([
            'id' => $user->id,
            'personal_id' => '4829175036',
            'fullname' => 'Tunde Babatunde Adeyemi',
            'username' => 'Tunde Adeyemi',
            'dob' => '1990-01-30',
            'gender' => 'Male',
        ]);

        EmergencyContact::create(['patient_id' => $patient->id, 'name' => 'Ada Adeyemi', 'relationship' => 'Sibling', 'phone' => '08000000002', 'priority' => 1]);
        EmergencyContact::create(['patient_id' => $patient->id, 'name' => 'Mama Tunde', 'relationship' => 'Parent', 'phone' => '08000000003', 'priority' => 2]);

        // The first contact has an account, so requests also show up inside their app.
        User::factory()->create(['phone' => '08000000002']);

        foreach ([
            ['Blood group', 'O positive', 'public'],
            ['Genotype', 'AA', 'public'],
            ['Allergy', 'Penicillin', 'public'],
            ['Current medication', 'None', 'public'],
            ['Health condition', 'Asthma', 'private'],
        ] as [$type, $title, $visibility]) {
            Record::create([
                'patient_id' => $patient->id, 'category' => RecordCategory::MedicalHistory, 'type' => $type,
                'title' => $title, 'visibility' => $visibility, 'created_by' => $patient->id,
            ]);
        }

        $card = $cards->issue();
        $cards->activate($patient, $card->card_number);
        $card->refresh();

        // ---- hospital -----------------------------------------------------------------------
        $org = Organisation::factory()->create(['name' => 'Perfect Medicals Inc.']);
        $org->user->update(['email' => 'admin@perfectmedicals.test']);
        ProviderPhone::create(['organisation_id' => $org->id, 'phone' => '08031112222', 'label' => 'Front desk', 'verified_at' => now()]);

        $front = User::factory()->create(['type' => UserType::OrgStaff, 'phone' => null, 'email' => 'front@perfectmedicals.test']);
        OrganisationStaff::create(['id' => $front->id, 'organisation_id' => $org->id, 'fullname' => 'Front Desk']);

        $this->command?->info("Demo card number: {$card->card_number}");
        $this->command?->info('Demo card QR token: '.$card->makeVisible('qr_token')->qr_token);
    }
}
