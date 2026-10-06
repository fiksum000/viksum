<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('packages', function(Blueprint $t){$t->id();$t->string('name');$t->unsignedBigInteger('price');$t->string('normal_profile');$t->string('normal_speed')->nullable();$t->boolean('burst_enabled')->default(false);$t->string('burst_limit')->nullable();$t->string('burst_threshold')->nullable();$t->unsignedTinyInteger('priority')->default(8);$t->boolean('fup_enabled')->default(false);$t->unsignedBigInteger('fup_limit_bytes')->nullable();$t->string('fup_speed_after')->nullable();$t->timestamps();$t->index('name');}); } public function down(): void { Schema::dropIfExists('packages'); } };
