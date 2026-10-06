<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('routers', function(Blueprint $t){$t->id();$t->string('name');$t->string('host');$t->unsignedSmallInteger('port')->default(8728);$t->string('username');$t->text('password');$t->boolean('ssl')->default(false);$t->boolean('enabled')->default(true);$t->timestamp('last_seen_at')->nullable();$t->json('meta')->nullable();$t->text('notes')->nullable();$t->timestamps();$t->unique(['host','port']);}); } public function down(): void { Schema::dropIfExists('routers'); } };
