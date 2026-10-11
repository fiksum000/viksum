<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fup_states', function (Blueprint $table): void {
            $table->string('last_session_id', 80)->nullable()->after('last_sampled_at');
        });
    }

    public function down(): void
    {
        Schema::table('fup_states', function (Blueprint $table): void {
            $table->dropColumn('last_session_id');
        });
    }
};
