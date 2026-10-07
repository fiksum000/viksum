<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 60);
            $table->date('scheduled_for');
            $table->string('status', 20)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'event', 'scheduled_for'], 'billing_notifications_invoice_event_day_unique');
            $table->index(['status', 'scheduled_for']);
        });

        DB::table('wa_templates')->insertOrIgnore([
            'event' => 'isolation_warning',
            'name' => 'Peringatan H-1 isolir',
            'body' => 'Halo {{name}}, tagihan {{invoice_number}} sebesar Rp {{amount}} belum dibayar. Layanan internet akan diisolir pada {{isolation_date}} jika pembayaran belum diterima. Bayar: {{payment_url}}',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_notifications');
        DB::table('wa_templates')->where('event', 'isolation_warning')->delete();
    }
};

