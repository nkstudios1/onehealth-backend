<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Local development data only. Refuses to run in production. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('The seeder creates a known password. It will not run in production.');

            return;
        }

        // A ready-made patient for trying login without signing up:
        //   login with 08000000001, personal ID 4829175036, or password Password1
        $user = User::factory()->create(['phone' => '08000000001']);

        Patient::factory()->create([
            'id' => $user->id,
            'personal_id' => '4829175036',
            'fullname' => 'Tunde Babatunde Adeyemi',
            'username' => 'Tunde Adeyemi',
        ]);
    }
}
