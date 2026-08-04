<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_form_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 30);
            $table->string('athlete_name', 140);
            $table->date('birth_date');
            $table->string('email', 180);
            $table->string('phone', 40);
            $table->string('program', 100);
            $table->string('experience', 120);
            $table->string('locality', 120)->nullable();
            $table->string('previous_club', 140)->nullable();
            $table->string('federation_number', 40)->nullable();
            $table->text('availability')->nullable();
            $table->string('guardian_name', 140)->nullable();
            $table->string('guardian_relationship', 80)->nullable();
            $table->string('guardian_email', 180)->nullable();
            $table->string('guardian_phone', 40)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('new');
            $table->timestampTz('privacy_consent_at');
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('payload')->nullable();
            $table->timestampsTz();

            $table->index(['type', 'status']);
            $table->index('created_at');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_form_submissions');
    }
};
