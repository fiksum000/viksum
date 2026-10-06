<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hotspot_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('username', 120);
            $table->text('password');
            $table->string('profile', 120);
            $table->string('comment')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();
            $table->unique(['router_id', 'username']);
            $table->index(['router_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotspot_vouchers');
    }
};
