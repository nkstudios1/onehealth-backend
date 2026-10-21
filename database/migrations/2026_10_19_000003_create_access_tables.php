<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * care_sessions: an organisation's access to a patient (named care_sessions because
 * Laravel already owns a `sessions` table for browser logins).
 * access_requests: a scan waiting to be answered. Approval creates the care session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignUuid('organisation_id')->constrained('organisations')->restrictOnDelete();
            $table->foreignUuid('opened_by')->constrained('users')->restrictOnDelete();
            $table->string('access_method', 30);                 // card_qr | card_number | emergency_contact | ...
            $table->string('access_level', 10)->default('limited'); // App\Enums\AccessLevel
            $table->timestamp('expires_at')->nullable();         // null = until check-out
            $table->string('status', 20)->default('active')->index();
            $table->foreignUuid('emergency_contact_id')->nullable()->constrained('emergency_contacts')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
            $table->index(['organisation_id', 'status']);
        });

        Schema::create('access_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignUuid('organisation_id')->constrained('organisations')->restrictOnDelete();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('card_id')->constrained('health_cards')->restrictOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('stage', 20)->default('patient');
            $table->unsignedSmallInteger('code_attempts')->default(0);
            $table->boolean('decided_by_patient')->nullable();
            $table->foreignUuid('decided_by_contact')->nullable()->constrained('emergency_contacts')->nullOnDelete();
            $table->string('decided_via', 20)->nullable();
            $table->foreignUuid('care_session_id')->nullable()->constrained('care_sessions')->nullOnDelete();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
        });

        // One row per person told about a request. Codes and link tokens are stored hashed.
        Schema::create('access_request_notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('access_request_id')->constrained('access_requests')->cascadeOnDelete();
            $table->string('recipient_type', 10);                // patient | contact
            $table->foreignUuid('emergency_contact_id')->nullable()->constrained('emergency_contacts')->cascadeOnDelete();
            $table->string('channel', 10);                       // app | sms | email
            $table->char('code_hash', 64)->nullable();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestamp('sent_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_request_notices');
        Schema::dropIfExists('access_requests');
        Schema::dropIfExists('care_sessions');
    }
};
