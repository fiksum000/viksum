<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('fup_states', function(Blueprint $t){$t->id();$t->foreignId('customer_id')->constrained()->cascadeOnDelete();$t->string('period',7);$t->unsignedBigInteger('last_rx')->default(0);$t->unsignedBigInteger('last_tx')->default(0);$t->unsignedBigInteger('total_bytes')->default(0);$t->boolean('limited')->default(false);$t->timestamp('last_sampled_at')->nullable();$t->timestamps();$t->unique(['customer_id','period']);}); } public function down(): void { Schema::dropIfExists('fup_states'); } };
