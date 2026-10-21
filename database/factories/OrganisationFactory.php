<?php

namespace Database\Factories;

use App\Enums\UserType;
use App\Enums\VerifyStatus;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Organisation> */
class OrganisationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => User::factory()->state(fn () => [
                'type' => UserType::OrgAdmin,
                'phone' => null,
                'email' => fake()->unique()->safeEmail(),
            ]),
            'name' => 'Perfect Medicals Inc.',
            'verify_status' => VerifyStatus::Verified,
        ];
    }

    public function unverified(): static
    {
        return $this->state(['verify_status' => VerifyStatus::Pending]);
    }
}
