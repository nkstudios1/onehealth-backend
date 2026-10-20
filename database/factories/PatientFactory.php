<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\User;
use App\Support\Hmac;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Patient> */
class PatientFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $nin = fake()->unique()->numerify('###########');

        return [
            'id' => User::factory(),
            'nin_hash' => Hmac::nin($nin),
            'nin_last4' => substr($nin, -4),
            'fullname' => 'Adaeze Chinwe Okafor',
            'username' => 'Adaeze Okafor',
            'dob' => '1992-04-17',
            'gender' => 'Female',
            'personal_id' => (string) fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999),
            'consented_at' => now(),
            'consent_version' => '2026-10',
        ];
    }
}
