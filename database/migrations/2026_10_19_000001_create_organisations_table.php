<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Schema v2: org profile (id = the org user's id) and the numbers a hospital may call contacts from. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreign('id')->references('id')->on('users')->restrictOnDelete();
            $table->string('name', 200);
            $table->string('verify_status', 20)->default('pending')->index(); // App\Enums\VerifyStatus
            $table->timestamps();
        });

        // Staff belong to an organisation. (Org admins are the organisation itself: id = org id.)
        Schema::create('organisation_staff', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreign('id')->references('id')->on('users')->restrictOnDelete();
            $table->foreignUuid('organisation_id')->constrained('organisations')->restrictOnDelete();
            $table->string('fullname', 200);
            $table->timestamps();
        });

        Schema::create('provider_phones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->char('phone', 11);
            $table->string('label', 100);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['organisation_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_phones');
        Schema::dropIfExists('organisation_staff');
        Schema::dropIfExists('organisations');
    }
};
