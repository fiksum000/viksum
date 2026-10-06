<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('fup_samples', function(Blueprint $t){$t->id();$t->foreignId('customer_id')->constrained()->cascadeOnDelete();$t->string('period',7);$t->unsignedBigInteger('rx_bytes')->default(0);$t->unsignedBigInteger('tx_bytes')->default(0);$t->unsignedBigInteger('delta_bytes')->default(0);$t->unsignedBigInteger('total_bytes_after')->default(0);$t->timestamp('sampled_at');$t->timestamps();$t->index(['customer_id','period','sampled_at']);}); } public function down(): void { Schema::dropIfExists('fup_samples'); } };
