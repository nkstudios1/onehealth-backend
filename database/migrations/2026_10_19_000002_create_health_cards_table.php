<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('card_number', 16)->unique();   // printed; fallback when the QR can't be read
            $table->string('qr_token', 64)->unique();    // random value in the QR, NOT the card number
            $table->foreignUuid('owner_id')->nullable()->constrained('patients')->restrictOnDelete();
            $table->string('status', 20)->default('issued')->index(); // App\Enums\CardStatus
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');             // issue + 4 years
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->string('deactivated_by', 20)->nullable();
            $table->string('reason_deactivated')->nullable();
            $table->foreignUuid('replaced_by')->nullable();
            $table->timestamps();
        });

        // Self-reference added in a second step: on Postgres the primary key is created after the
        // columns, so a foreign key to this same table inside create() fails ("no unique constraint").
        Schema::table('health_cards', function (Blueprint $table) {
            $table->foreign('replaced_by')->references('id')->on('health_cards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_cards');
    }
};
