<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('area', 120)->nullable();
            $table->string('whatsapp_number', 30)->nullable();
            $table->string('modem_device', 120)->nullable();
            $table->string('source_port', 120)->nullable();
            $table->text('notes')->nullable();
            $table->text('npwp')->nullable();
            $table->date('registered_at')->nullable();
            $table->boolean('fup_override')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['fup_override']);
            $table->dropColumn(['area', 'whatsapp_number', 'modem_device', 'source_port', 'notes', 'npwp', 'registered_at', 'fup_override']);
        });
    }
};
