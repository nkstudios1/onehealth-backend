<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema v2: `user` (called `users` here, the Laravel convention, which also avoids
 * `user` being a reserved word in PostgreSQL) plus the session table.
 *
 * One table for every account type (patient, org_admin, org_staff, platform_admin).
 * Type-specific data lives in its own table (`patients` ...), keyed by the same id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 20)->index();             // App\Enums\UserType
            $table->string('email')->nullable()->unique();   // stored lower-case; optional for patients
            $table->timestamp('email_verified_at')->nullable();
            $table->char('phone', 11)->nullable()->unique(); // local form, 08012345678
            $table->text('photo')->nullable();
            $table->string('password');
            $table->string('google_sub')->nullable()->unique();
            $table->string('status', 20)->default('active')->index(); // App\Enums\AccountStatus
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // Browser sessions. user_id is a uuid here (the Laravel default is a number),
        // otherwise "log out everywhere" and password-reset session revocation break.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
