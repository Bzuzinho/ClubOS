<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->default('bscn');
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('modality', 64)->default('swimming');
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['club_id', 'code'], 'uq_training_groups_club_code');
            $table->index(['club_id', 'active', 'name'], 'idx_training_groups_club_active_name');
        });

        Schema::create('training_group_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->default('bscn');
            $table->foreignUuid('training_group_id')->constrained('training_groups')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['club_id', 'training_group_id', 'user_id', 'starts_at'],
                'uq_training_group_memberships_period'
            );
            $table->index(
                ['club_id', 'user_id', 'is_primary', 'starts_at', 'ends_at'],
                'idx_training_group_memberships_user_period'
            );
        });

        Schema::create('training_group_coaches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->default('bscn');
            $table->foreignUuid('training_group_id')->constrained('training_groups')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 32)->default('assistant');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['club_id', 'training_group_id', 'user_id', 'starts_at'],
                'uq_training_group_coaches_period'
            );
            $table->index(
                ['club_id', 'user_id', 'starts_at', 'ends_at'],
                'idx_training_group_coaches_user_period'
            );
        });

        Schema::create('training_group_age_groups', function (Blueprint $table): void {
            $table->string('club_id', 64)->default('bscn');
            $table->foreignUuid('training_group_id')->constrained('training_groups')->cascadeOnDelete();
            $table->foreignUuid('age_group_id')->constrained('age_groups')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['club_id', 'training_group_id', 'age_group_id'],
                'uq_training_group_age_groups'
            );
        });

        Schema::create('sports_objectives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->default('bscn');
            $table->string('target_type', 32);
            $table->uuid('target_id')->nullable();
            $table->string('modality', 64)->nullable();
            $table->string('status', 32)->default('active');
            $table->unsignedInteger('current_version')->default(1);
            $table->date('starts_at')->nullable();
            $table->date('due_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['club_id', 'target_type', 'target_id', 'status'],
                'idx_sports_objectives_target'
            );
        });

        Schema::create('sports_objective_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->default('bscn');
            $table->foreignUuid('sports_objective_id')->constrained('sports_objectives')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('objective_type', 32)->default('text');
            $table->string('indicator_key', 128)->nullable();
            $table->decimal('target_value', 14, 4)->nullable();
            $table->text('target_text')->nullable();
            $table->string('target_unit', 64)->nullable();
            $table->json('visibility')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['sports_objective_id', 'version'],
                'uq_sports_objective_versions_number'
            );
            $table->index(['club_id', 'sports_objective_id'], 'idx_sports_objective_versions_club');
        });

        Schema::create('athlete_indicator_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->default('bscn');
            $table->string('code', 96);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('data_type', 32)->default('number');
            $table->string('unit', 64)->nullable();
            $table->string('category', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true);
            $table->boolean('shareable_by_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['club_id', 'code'], 'uq_athlete_indicator_definitions_code');
            $table->index(
                ['club_id', 'active', 'category', 'sort_order'],
                'idx_athlete_indicator_definitions_catalog'
            );
        });

        Schema::create('athlete_indicator_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->default('bscn');
            $table->foreignUuid('indicator_definition_id')
                ->constrained('athlete_indicator_definitions')
                ->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('definition_version');
            $table->string('indicator_code', 96);
            $table->string('indicator_name');
            $table->string('indicator_unit', 64)->nullable();
            $table->string('indicator_category', 64)->nullable();
            $table->string('data_type', 32);
            $table->decimal('value_numeric', 18, 6)->nullable();
            $table->text('value_text')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->unsignedBigInteger('value_milliseconds')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamp('recorded_at');
            $table->text('notes')->nullable();
            $table->boolean('shareable')->default(false);
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['club_id', 'user_id', 'indicator_definition_id', 'recorded_at'],
                'idx_athlete_indicator_records_history'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_indicator_records');
        Schema::dropIfExists('athlete_indicator_definitions');
        Schema::dropIfExists('sports_objective_versions');
        Schema::dropIfExists('sports_objectives');
        Schema::dropIfExists('training_group_age_groups');
        Schema::dropIfExists('training_group_coaches');
        Schema::dropIfExists('training_group_memberships');
        Schema::dropIfExists('training_groups');
    }
};
