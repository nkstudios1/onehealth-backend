<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Schema v2: `patient` and `emergency_contact`. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // restrictOnDelete: deleting a user must never silently delete a patient's data.
            $table->foreign('id')->references('id')->on('users')->restrictOnDelete();

            $table->char('nin_hash', 64)->unique();   // HMAC-SHA256 of the NIN (hex). The raw NIN is never stored.
            $table->char('nin_last4', 4);             // display only
            $table->string('fullname', 200);          // exactly as on the NIN record
            $table->string('username', 200);          // display name (first and last name)
            $table->date('dob');
            $table->string('gender', 20);
            $table->char('personal_id', 10)->unique(); // random, permanent, public
            $table->text('home_address')->nullable();
            $table->text('work_address')->nullable();

            // Proof of consent (NDPA): what the patient agreed to, and when.
            $table->timestamp('consented_at');
            $table->string('consent_version', 20);

            $table->timestamps();
        });

        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('name', 200);
            $table->string('relationship', 100);       // free text; the app only suggests values
            $table->char('phone', 11);
            $table->string('email')->nullable();
            $table->unsignedTinyInteger('priority');   // 1, 2 or 3: the order contacts are called
            $table->timestamps();

            $table->unique(['patient_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_contacts');
        Schema::dropIfExists('patients');
    }
};
