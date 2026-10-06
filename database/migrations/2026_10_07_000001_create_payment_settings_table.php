<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('tripay_enabled')->default(false);
            $table->string('tripay_mode', 20)->default('sandbox');
            $table->text('tripay_api_key')->nullable();
            $table->text('tripay_private_key')->nullable();
            $table->string('tripay_merchant_code', 100)->nullable();
            $table->text('tripay_callback_url')->nullable();
            $table->text('tripay_return_url')->nullable();
            $table->text('tripay_whitelist_ips')->nullable();
            $table->string('settlement_account_name', 150)->nullable();
            $table->string('settlement_bank_name', 100)->nullable();
            $table->text('settlement_account_number')->nullable();
            $table->string('merchant_name', 150)->nullable();
            $table->string('merchant_logo_path')->nullable();
            $table->string('merchant_website')->nullable();
            $table->string('demo_username', 120)->nullable();
            $table->text('demo_password')->nullable();
            $table->date('demo_isolation_date')->nullable();
            $table->text('merchant_description')->nullable();
            $table->boolean('dana_enabled')->default(false);
            $table->string('dana_account_name', 120)->nullable();
            $table->string('dana_phone', 40)->nullable();
            $table->string('dana_qr_path')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
