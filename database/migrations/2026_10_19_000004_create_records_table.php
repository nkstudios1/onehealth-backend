<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIMPLIFIED first version of schema v2 `record`: one row per record, no version history yet.
 * (The full design splits this into `record` + append-only `record_version`. Moving to that
 * later only changes this module.) Medical content is free text; the patient owns visibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('category', 30);                       // App\Enums\RecordCategory
            $table->string('type', 100);                          // free text: "Blood group", "Allergy", "Consultation"...
            $table->string('title', 200);                         // free text: "O positive", "Penicillin"
            $table->text('summary')->nullable();                  // free text details
            $table->json('payload')->nullable();                  // structured extras (e.g. vitals readings)
            $table->string('visibility', 10)->default('private'); // App\Enums\RecordVisibility
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('event_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'category']);
            $table->index(['patient_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
