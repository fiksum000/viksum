<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_logs', function (Blueprint $table): void {
            $table->foreignId('billing_notification_id')->nullable()->after('customer_id')->constrained('billing_notifications')->nullOnDelete();
            $table->string('provider_message_id', 120)->nullable()->index()->after('status');
            $table->string('provider_status', 60)->nullable()->after('provider_message_id');
            $table->string('provider_state', 120)->nullable()->after('provider_status');
            $table->string('provider_state_id', 120)->nullable()->after('provider_state');
        });
    }

    public function down(): void
    {
        Schema::table('wa_logs', function (Blueprint $table): void {
            $table->dropForeign(['billing_notification_id']);
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn(['billing_notification_id', 'provider_message_id', 'provider_status', 'provider_state', 'provider_state_id']);
        });
    }
};

