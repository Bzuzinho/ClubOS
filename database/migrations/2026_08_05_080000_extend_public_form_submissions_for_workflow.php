<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_form_submissions', function (Blueprint $table) {
            $table->foreignUuid('user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->foreignUuid('processed_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('identity_fingerprint', 64)->nullable()->after('processed_by');
            $table->timestampTz('processed_at')->nullable()->after('identity_fingerprint');
            $table->timestampTz('email_queued_at')->nullable()->after('processed_at');
            $table->timestampTz('admin_notified_at')->nullable()->after('email_queued_at');

            $table->index('user_id');
            $table->index('identity_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('public_form_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('processed_by');
            $table->dropConstrainedForeignId('user_id');
            $table->dropIndex(['identity_fingerprint']);
            $table->dropColumn([
                'identity_fingerprint',
                'processed_at',
                'email_queued_at',
                'admin_notified_at',
            ]);
        });
    }
};
