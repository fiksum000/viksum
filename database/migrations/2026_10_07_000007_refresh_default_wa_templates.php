<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    private array $templates = [
        'billing_reminder' => [
            'Halo {{name}}, tagihan {{invoice_number}} sebesar Rp {{amount}} jatuh tempo {{due_date}}. Bayar: {{payment_url}}',
            'Yth. {{name}}, kami mengingatkan tagihan {{invoice_number}} sebesar Rp {{amount}} yang jatuh tempo pada {{due_date}}. ID pelanggan: {{customer_code}}. Username layanan: {{service_username}}. Silakan lakukan pembayaran melalui {{payment_url}}. Portal pelanggan: {{portal_url}} (ID login: {{portal_username}}). Terima kasih.',
        ],
        'isolation_warning' => [
            'Halo {{name}}, tagihan {{invoice_number}} sebesar Rp {{amount}} belum dibayar. Layanan internet akan diisolir pada {{isolation_date}} jika pembayaran belum diterima. Bayar: {{payment_url}}',
            'Yth. {{name}}, tagihan {{invoice_number}} sebesar Rp {{amount}} belum kami terima. Mohon lakukan pembayaran sebelum {{isolation_date}} agar layanan tidak diisolir. ID pelanggan: {{customer_code}}. Username layanan: {{service_username}}. Pembayaran: {{payment_url}}. Portal pelanggan: {{portal_url}} (ID login: {{portal_username}}). Terima kasih.',
        ],
        'payment_success' => [
            'Halo {{name}}, pembayaran {{invoice_number}} sebesar Rp {{amount}} berhasil kami terima. Terima kasih.',
            'Yth. {{name}}, pembayaran {{invoice_number}} sebesar Rp {{amount}} telah kami terima. Terima kasih telah melakukan pembayaran. ID pelanggan: {{customer_code}}. Username layanan: {{service_username}}. Portal pelanggan: {{portal_url}} (ID login: {{portal_username}}).',
        ],
        'isolation' => [
            'Halo {{name}}, layanan internet diisolir karena tagihan belum dibayar. Silakan lakukan pembayaran untuk aktivasi kembali.',
            'Yth. {{name}}, layanan internet Anda telah diisolir sementara karena tagihan belum dibayar. Silakan selesaikan pembayaran agar layanan dapat dipulihkan. ID pelanggan: {{customer_code}}. Username layanan: {{service_username}}. Portal pelanggan: {{portal_url}} (ID login: {{portal_username}}). Terima kasih.',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('wa_templates')) {
            return;
        }

        foreach ($this->templates as $event => [$oldBody, $newBody]) {
            DB::table('wa_templates')
                ->where('event', $event)
                ->where('body', $oldBody)
                ->update(['body' => $newBody, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('wa_templates')) {
            return;
        }

        foreach ($this->templates as $event => [$oldBody, $newBody]) {
            DB::table('wa_templates')
                ->where('event', $event)
                ->where('body', $newBody)
                ->update(['body' => $oldBody, 'updated_at' => now()]);
        }
    }
};
