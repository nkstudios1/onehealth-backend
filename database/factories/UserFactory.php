<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => UserType::Patient,
            'phone' => '080'.fake()->unique()->numerify('########'),
            'email' => null,
            'password' => static::$password ??= Hash::make('Password1'),
            'status' => AccountStatus::Active,
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => AccountStatus::Suspended]);
    }
}
