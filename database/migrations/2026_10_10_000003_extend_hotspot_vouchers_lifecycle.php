<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotspot_vouchers', function (Blueprint $table): void {
            $table->foreignId('hotspot_profile_id')->nullable()->after('router_id')
                ->constrained('hotspot_profiles')->nullOnDelete();
            $table->timestamp('first_login_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('total_bytes_used')->default(0);
            $table->unsignedBigInteger('last_bytes_in')->default(0);
            $table->unsignedBigInteger('last_bytes_out')->default(0);
            $table->timestamp('last_sampled_at')->nullable();
            $table->boolean('fup_applied')->default(false);
            $table->string('sync_status', 20)->default('pending');
            $table->text('sync_error')->nullable();
            $table->index(['status', 'expires_at']);
            $table->index(['router_id', 'sync_status']);
        });
    }

    public function down(): void
    {
        Schema::table('hotspot_vouchers', function (Blueprint $table): void {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropIndex(['router_id', 'sync_status']);
            $table->dropConstrainedForeignId('hotspot_profile_id');
            $table->dropColumn([
                'first_login_at',
                'expires_at',
                'total_bytes_used',
                'last_bytes_in',
                'last_bytes_out',
                'last_sampled_at',
                'fup_applied',
                'sync_status',
                'sync_error',
            ]);
        });
    }
};
