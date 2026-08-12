<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $clubId = trim((string) config('sports.club_id', 'bscn')) ?: 'bscn';

        if (Schema::hasTable('competitions')) {
            Schema::table('competitions', function (Blueprint $table): void {
                if (! Schema::hasColumn('competitions', 'club_id')) {
                    $table->string('club_id', 64)->nullable()->index();
                }
                if (! Schema::hasColumn('competitions', 'status')) {
                    $table->string('status', 24)->default('scheduled')->index();
                }
                if (! Schema::hasColumn('competitions', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable();
                }
                if (! Schema::hasColumn('competitions', 'archived_at')) {
                    $table->timestamp('archived_at')->nullable();
                }
                if (! Schema::hasColumn('competitions', 'cancellation_reason')) {
                    $table->text('cancellation_reason')->nullable();
                }
                if (! Schema::hasColumn('competitions', 'created_by')) {
                    $table->uuid('created_by')->nullable()->index();
                }
                if (! Schema::hasColumn('competitions', 'updated_by')) {
                    $table->uuid('updated_by')->nullable()->index();
                }
            });

            DB::table('competitions')->whereNull('club_id')->update(['club_id' => $clubId]);
            DB::table('competitions')->whereNull('status')->update(['status' => 'scheduled']);
        }

        if (! Schema::hasTable('competition_event_projections')) {
            Schema::create('competition_event_projections', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('club_id', 64)->index();
                $table->uuid('competition_id')->unique();
                $table->uuid('event_id')->nullable()->unique();
                $table->uuid('legacy_event_id')->nullable()->index();
                $table->string('status', 32)->default('pending_projection')->index();
                $table->text('manual_review_reason')->nullable();
                $table->timestamp('projected_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();

                $table->foreign('competition_id')
                    ->references('id')
                    ->on('competitions')
                    ->cascadeOnDelete();
                $table->foreign('event_id')
                    ->references('id')
                    ->on('events')
                    ->nullOnDelete();
            });
        }

        $this->backfillProjectionRows($clubId);
    }

    private function backfillProjectionRows(string $clubId): void
    {
        if (! Schema::hasTable('competitions') || ! Schema::hasTable('competition_event_projections')) {
            return;
        }

        $competitions = DB::table('competitions')
            ->orderBy('id')
            ->get(['id', 'evento_id']);

        foreach ($competitions as $competition) {
            if (DB::table('competition_event_projections')->where('competition_id', $competition->id)->exists()) {
                continue;
            }

            $legacyEventId = filled($competition->evento_id) ? (string) $competition->evento_id : null;
            $eventId = null;
            $status = 'pending_projection';
            $reviewReason = null;

            if ($legacyEventId !== null) {
                $legacyReferenceCount = DB::table('competitions')
                    ->where('evento_id', $legacyEventId)
                    ->count();
                $eventExists = Schema::hasTable('events')
                    && DB::table('events')->where('id', $legacyEventId)->exists();

                if ($legacyReferenceCount === 1 && $eventExists) {
                    $eventId = $legacyEventId;
                    $status = 'linked';
                } elseif (! $eventExists) {
                    $status = 'manual_review';
                    $reviewReason = 'legacy_event_missing';
                } else {
                    $status = 'manual_review';
                    $reviewReason = 'legacy_event_shared_by_multiple_competitions';
                }
            }

            DB::table('competition_event_projections')->insert([
                'id' => (string) Str::uuid(),
                'club_id' => $clubId,
                'competition_id' => (string) $competition->id,
                'event_id' => $eventId,
                'legacy_event_id' => $legacyEventId,
                'status' => $status,
                'manual_review_reason' => $reviewReason,
                'projected_at' => $eventId !== null ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_event_projections');

        if (! Schema::hasTable('competitions')) {
            return;
        }

        Schema::table('competitions', function (Blueprint $table): void {
            $columns = collect([
                'club_id',
                'status',
                'cancelled_at',
                'archived_at',
                'cancellation_reason',
                'created_by',
                'updated_by',
            ])->filter(fn (string $column): bool => Schema::hasColumn('competitions', $column))->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
