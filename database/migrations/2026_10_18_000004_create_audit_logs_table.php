<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of security-relevant events (logins, failures, resets...).
 * Rows are never updated or deleted (the model refuses). No secrets in `metadata`:
 * never a password, code, token or full NIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event', 60)->index();     // App\Enums\AuditEvent
            $table->uuid('actor_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('request_id', 36)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
