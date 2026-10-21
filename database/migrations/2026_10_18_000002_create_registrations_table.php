<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sign-up in progress, plus the one-time codes (shared with password reset).
 *
 * A registration holds personal data (name, date of birth) only until the
 * account is created, then the row is deleted. Abandoned ones are removed by
 * `php artisan onehealth:prune`. The raw NIN is never written here, only its hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();           // this is the registrationId the app holds
            $table->char('phone', 11)->index();
            $table->char('nin_hash', 64);
            $table->char('nin_last4', 4);
            $table->string('full_name', 200);
            $table->date('dob');
            $table->string('gender', 20);
            $table->text('photo_url')->nullable();
            $table->string('step', 30);              // App\Enums\RegistrationStep
            $table->json('contacts')->nullable();    // emergency contacts, saved with the account at the end
            $table->timestamp('consented_at');
            $table->string('consent_version', 20);
            $table->timestamp('otp_sent_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('purpose', 30);           // App\Enums\OtpPurpose
            $table->uuid('subject_id');              // the registration or password reset this code belongs to
            $table->char('code_hash', 64);           // HMAC, never the code itself
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['purpose', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('registrations');
    }
};
