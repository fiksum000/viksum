<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->string('upload_speed', 30)->nullable()->after('normal_speed');
            $table->string('download_speed', 30)->nullable()->after('upload_speed');
            $table->string('fup_upload_speed', 30)->nullable()->after('fup_limit_bytes');
            $table->string('fup_download_speed', 30)->nullable()->after('fup_upload_speed');
            $table->string('legacy_normal_profile', 120)->nullable()->after('normal_profile');
            $table->string('legacy_fup_profile', 120)->nullable()->after('fup_speed_after');
            $table->string('sync_status', 20)->default('legacy')->after('legacy_fup_profile');
            $table->text('sync_error')->nullable()->after('sync_status');
            $table->timestamp('last_synced_at')->nullable()->after('sync_error');
            $table->string('burst_time', 8)->default('5s')->after('burst_threshold');
            $table->index(['router_id', 'sync_status']);
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropIndex(['router_id', 'sync_status']);
            $table->dropColumn([
                'upload_speed',
                'download_speed',
                'fup_upload_speed',
                'fup_download_speed',
                'legacy_normal_profile',
                'legacy_fup_profile',
                'sync_status',
                'sync_error',
                'last_synced_at',
                'burst_time',
            ]);
        });
    }
};
