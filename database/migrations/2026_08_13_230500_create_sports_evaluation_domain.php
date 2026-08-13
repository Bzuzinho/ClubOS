<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_evaluation_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('state', 20)->default('draft');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['club_id', 'state']);
        });

        Schema::create('sports_evaluation_model_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('evaluation_model_id');
            $table->unsignedInteger('version_number');
            $table->string('state', 20)->default('draft');
            $table->uuid('based_on_version_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->foreign('evaluation_model_id')->references('id')->on('sports_evaluation_models')->cascadeOnDelete();
            $table->foreign('based_on_version_id')->references('id')->on('sports_evaluation_model_versions')->nullOnDelete();
            $table->unique(['evaluation_model_id', 'version_number'], 'sports_eval_model_version_unique');
        });

        Schema::create('sports_evaluation_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('evaluation_model_version_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('weight', 7, 3)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->foreign('evaluation_model_version_id')->references('id')->on('sports_evaluation_model_versions')->cascadeOnDelete();
            $table->index(['evaluation_model_version_id', 'sort_order']);
        });

        Schema::create('sports_evaluation_criteria', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('evaluation_section_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('response_type', 20)->default('scale');
            $table->decimal('min_value', 12, 3)->nullable();
            $table->decimal('max_value', 12, 3)->nullable();
            $table->json('options_json')->nullable();
            $table->decimal('weight', 7, 3)->default(0);
            $table->boolean('required')->default(true);
            $table->boolean('allow_comment')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->foreign('evaluation_section_id')->references('id')->on('sports_evaluation_sections')->cascadeOnDelete();
            $table->index(['evaluation_section_id', 'sort_order']);
        });

        Schema::create('sports_evaluation_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id');
            $table->uuid('evaluation_model_version_id');
            $table->uuid('season_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('due_at')->nullable();
            $table->string('state', 20)->default('draft');
            $table->uuid('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->foreign('evaluation_model_version_id')->references('id')->on('sports_evaluation_model_versions')->restrictOnDelete();
            $table->index(['club_id', 'state', 'starts_at']);
        });

        Schema::create('sports_evaluation_campaign_groups', function (Blueprint $table): void {
            $table->uuid('campaign_id');
            $table->uuid('training_group_id');
            $table->timestamps();
            $table->foreign('campaign_id')->references('id')->on('sports_evaluation_campaigns')->cascadeOnDelete();
            $table->foreign('training_group_id')->references('id')->on('training_groups')->cascadeOnDelete();
            $table->unique(['campaign_id', 'training_group_id'], 'sports_eval_campaign_group_unique');
        });

        Schema::create('sports_evaluation_campaign_athletes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->uuid('user_id');
            $table->string('state', 20)->default('pending');
            $table->text('exclusion_reason')->nullable();
            $table->timestamp('included_at')->nullable();
            $table->timestamps();
            $table->foreign('campaign_id')->references('id')->on('sports_evaluation_campaigns')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['campaign_id', 'user_id'], 'sports_eval_campaign_athlete_unique');
            $table->index(['campaign_id', 'state']);
        });

        Schema::create('sports_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->uuid('campaign_athlete_id');
            $table->uuid('athlete_user_id');
            $table->uuid('evaluator_user_id')->nullable();
            $table->string('state', 20)->default('draft');
            $table->text('summary')->nullable();
            $table->text('objectives')->nullable();
            $table->decimal('overall_score', 12, 4)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->uuid('reopened_by')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
            $table->foreign('campaign_id')->references('id')->on('sports_evaluation_campaigns')->cascadeOnDelete();
            $table->foreign('campaign_athlete_id')->references('id')->on('sports_evaluation_campaign_athletes')->cascadeOnDelete();
            $table->foreign('athlete_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('evaluator_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reopened_by')->references('id')->on('users')->nullOnDelete();
            $table->unique('campaign_athlete_id');
            $table->index(['athlete_user_id', 'state']);
        });

        Schema::create('sports_evaluation_answers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('evaluation_id');
            $table->uuid('criterion_id')->nullable();
            $table->string('criterion_name_snapshot');
            $table->string('section_name_snapshot');
            $table->string('response_type_snapshot', 20);
            $table->decimal('weight_snapshot', 7, 3)->default(0);
            $table->decimal('min_value_snapshot', 12, 3)->nullable();
            $table->decimal('max_value_snapshot', 12, 3)->nullable();
            $table->json('options_snapshot')->nullable();
            $table->decimal('value_number', 16, 4)->nullable();
            $table->text('value_text')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->string('value_choice')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->foreign('evaluation_id')->references('id')->on('sports_evaluations')->cascadeOnDelete();
            $table->foreign('criterion_id')->references('id')->on('sports_evaluation_criteria')->nullOnDelete();
            $table->unique(['evaluation_id', 'criterion_id'], 'sports_eval_answer_criterion_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_evaluation_answers');
        Schema::dropIfExists('sports_evaluations');
        Schema::dropIfExists('sports_evaluation_campaign_athletes');
        Schema::dropIfExists('sports_evaluation_campaign_groups');
        Schema::dropIfExists('sports_evaluation_campaigns');
        Schema::dropIfExists('sports_evaluation_criteria');
        Schema::dropIfExists('sports_evaluation_sections');
        Schema::dropIfExists('sports_evaluation_model_versions');
        Schema::dropIfExists('sports_evaluation_models');
    }
};
