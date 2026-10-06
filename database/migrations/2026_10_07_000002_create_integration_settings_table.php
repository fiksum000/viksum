<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('integration_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('fonnte_enabled')->default(false);
            $table->string('fonnte_account_name', 120)->nullable();
            $table->text('fonnte_api_token')->nullable();
            $table->text('fonnte_webhook_token')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_settings');
    }
};
