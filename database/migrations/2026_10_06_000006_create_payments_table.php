<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('payments', function(Blueprint $t){$t->id();$t->foreignId('invoice_id')->constrained()->cascadeOnDelete();$t->string('provider')->default('tripay');$t->string('reference')->nullable()->unique();$t->string('merchant_ref')->nullable()->index();$t->string('channel')->nullable();$t->unsignedBigInteger('amount')->default(0);$t->string('status')->default('unpaid')->index();$t->string('checkout_url')->nullable();$t->json('raw_payload')->nullable();$t->timestamp('paid_at')->nullable();$t->timestamps();}); } public function down(): void { Schema::dropIfExists('payments'); } };
