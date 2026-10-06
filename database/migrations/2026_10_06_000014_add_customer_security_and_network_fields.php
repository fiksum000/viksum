<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->text('ktp_number')->nullable();
            $table->string('pppoe_ip', 45)->nullable();
            $table->string('pppoe_mac', 17)->nullable();
            $table->string('portal_password')->nullable();
        });

        Schema::create('olts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('vendor')->nullable();
            $table->string('model')->nullable();
            $table->string('host')->nullable();
            $table->string('management_protocol')->default('manual');
            $table->unsignedSmallInteger('management_port')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('status')->default('unknown');
            $table->timestamp('last_seen_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'status']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('olt_id')->nullable()->after('onu_sn')->constrained()->nullOnDelete();
        });

        Schema::create('onus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('pon_port', 50);
            $table->string('onu_id', 50);
            $table->string('serial_number', 100)->nullable();
            $table->string('mac_address', 17)->nullable();
            $table->decimal('rx_power', 7, 2)->nullable();
            $table->decimal('tx_power', 7, 2)->nullable();
            $table->decimal('temperature', 6, 2)->nullable();
            $table->string('uptime')->nullable();
            $table->string('status')->default('unknown');
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['olt_id', 'pon_port', 'onu_id']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('tripay_callbacks', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable()->index();
            $table->string('merchant_ref')->nullable()->index();
            $table->string('event')->nullable();
            $table->string('payload_hash', 64)->unique();
            $table->boolean('signature_valid')->default(false);
            $table->string('processing_status')->default('received');
            $table->text('processing_error')->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['merchant_ref', 'processing_status']);
        });

        Schema::create('fup_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);
            $table->string('action');
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->string('profile_before')->nullable();
            $table->string('profile_after')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'period', 'action']);
        });

        Schema::create('wa_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('event')->unique();
            $table->string('name');
            $table->text('body');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_templates');
        Schema::dropIfExists('fup_logs');
        Schema::dropIfExists('tripay_callbacks');
        Schema::dropIfExists('onus');
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('olt_id');
        });
        Schema::dropIfExists('olts');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['ktp_number', 'pppoe_ip', 'pppoe_mac', 'portal_password']);
        });
    }
};
