<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotspot_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('download_speed', 30)->default('5M');
            $table->string('upload_speed', 30)->default('1M');
            $table->unsignedSmallInteger('shared_users')->default(1);
            $table->unsignedBigInteger('fup_limit_bytes')->nullable();
            $table->string('fup_download_speed', 30)->nullable();
            $table->string('fup_upload_speed', 30)->nullable();
            $table->unsignedSmallInteger('validity_value')->nullable();
            $table->string('validity_unit', 10)->nullable();
            $table->boolean('starts_on_first_login')->default(true);
            $table->boolean('bind_mac')->default(false);
            $table->boolean('enabled')->default(true);
            $table->string('sync_status', 20)->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['router_id', 'name']);
            $table->index(['router_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotspot_profiles');
    }
};
