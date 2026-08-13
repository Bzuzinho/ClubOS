<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table): void {
            if (! Schema::hasColumn('trainings', 'cancelled_at')) $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            if (! Schema::hasColumn('trainings', 'cancelled_by')) $table->uuid('cancelled_by')->nullable()->index()->after('cancelled_at');
            if (! Schema::hasColumn('trainings', 'cancellation_reason')) $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            if (! Schema::hasColumn('trainings', 'content_override_at')) $table->timestamp('content_override_at')->nullable()->after('cancellation_reason');
            if (! Schema::hasColumn('trainings', 'content_override_by')) $table->uuid('content_override_by')->nullable()->index()->after('content_override_at');
            if (! Schema::hasColumn('trainings', 'content_override_reason')) $table->text('content_override_reason')->nullable()->after('content_override_by');
        });

        if (! Schema::hasTable('training_session_content_revisions')) {
            Schema::create('training_session_content_revisions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('club_id', 64)->index();
                $table->uuid('training_id')->index();
                $table->string('revision_type', 32)->default('snapshot_override');
                $table->uuid('source_plan_version_id')->nullable()->index();
                $table->text('reason');
                $table->json('before_snapshot')->nullable();
                $table->json('after_snapshot')->nullable();
                $table->uuid('created_by')->nullable()->index();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['club_id', 'training_id', 'created_at'], 'training_content_revision_lookup');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_session_content_revisions');
        Schema::table('trainings', function (Blueprint $table): void {
            foreach (['content_override_reason','content_override_by','content_override_at','cancellation_reason','cancelled_by','cancelled_at'] as $column) {
                if (Schema::hasColumn('trainings', $column)) $table->dropColumn($column);
            }
        });
    }
};
