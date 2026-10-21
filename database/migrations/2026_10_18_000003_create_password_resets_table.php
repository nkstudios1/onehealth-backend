<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per "I forgot my password" attempt. A row is created even when no
 * account matches what was typed (user_id stays null), so the response never
 * reveals whether an account exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_resets', function (Blueprint $table) {
            $table->uuid('id')->primary();           // the resetId the app holds
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('channel', 10);           // sms | email
            $table->char('identifier_hash', 64)->index();
            $table->string('destination');           // masked, safe to show: 080****5678
            $table->timestamp('otp_sent_at')->nullable();
            $table->timestamp('otp_verified_at')->nullable();
            $table->char('token_hash', 64)->nullable()->unique(); // proof-of-code token, stored hashed
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};
