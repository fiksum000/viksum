<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $templates = [
        'billing_reminder' => [
            'Yth. {{name}}, kami mengingatkan tagihan {{invoice_number}} sebesar Rp {{amount}} yang jatuh tempo pada {{due_date}}. ID pelanggan: {{customer_code}}. Username layanan: {{service_username}}. Silakan lakukan pembayaran melalui {{payment_url}}. Portal pelanggan: {{portal_url}} (ID login: {{portal_username}}). Terima kasih.',
            "Yth. {{name}},\n\nKami mengingatkan tagihan internet Anda:\nNo. invoice: {{invoice_number}}\nID pelanggan: {{customer_code}}\nUsername layanan: {{service_username}}\nJumlah tagihan: Rp {{amount}}\nJatuh tempo: {{due_date}}\n\nPembayaran: {{payment_url}}\nPortal pelanggan: {{portal_url}}\nID login: {{portal_username}}\n\nTerima kasih.\nFIKSUM",
        ],
        'isolation_warning' => [
            'Yth. {{name}}, tagihan {{invoice_number}} sebesar Rp {{amount}} belum kami terima. Mohon lakukan pembayaran sebelum {{isolation_date}} agar layanan tidak diisolir. ID pelanggan: {{customer_code}}. Username layanan: {{service_username}}. Pembayaran: {{payment_url}}. Portal pelanggan: {{portal_url}} (ID login: {{portal_username}}). Terima kasih.',
            "Yth. {{name}},\n\nTagihan internet Anda belum kami terima:\nNo. invoice: {{invoice_number}}\nID pelanggan: {{customer_code}}\nUsername layanan: {{service_username}}\nJumlah tagihan: Rp {{amount}}\nBatas pembayaran: {{isolation_date}}\n\nPembayaran: {{payment_url}}\nPortal pelanggan: {{portal_url}}\nID login: {{portal_username}}\n\nMohon lakukan pembayaran sebelum batas waktu agar layanan tidak diisolir.\nFIKSUM",
        ],
        'payment_success' => [
            'Yth. {{name}}, pembayaran {{invoice_number}} sebesar Rp {{amount}} telah kami terima. Terima kasih telah melakukan pembayaran. ID pelanggan: {{customer_code}}. Username layanan: {{service_username}}. Portal pelanggan: {{portal_url}} (ID login: {{portal_username}}).',
            "Yth. {{name}},\n\nKonfirmasi pembayaran\nNo. invoice: {{invoice_number}}\nStatus: Lunas\nID pelanggan: {{customer_code}}\nUsername layanan: {{service_username}}\nJumlah dibayar: Rp {{amount}}\n\nPortal pelanggan: {{portal_url}}\nID login: {{portal_username}}\n\nTerima kasih atas pembayaran Anda.\nFIKSUM",
        ],
        'isolation' => [
            'Yth. {{name}}, layanan internet Anda telah diisolir sementara karena tagihan belum dibayar. Silakan selesaikan pembayaran agar layanan dapat dipulihkan. ID pelanggan: {{customer_code}}. Username layanan: {{service_username}}. Portal pelanggan: {{portal_url}} (ID login: {{portal_username}}). Terima kasih.',
            "Yth. {{name}},\n\nLayanan internet Anda sedang diisolir karena tagihan belum dibayar.\nID pelanggan: {{customer_code}}\nUsername layanan: {{service_username}}\n\nSelesaikan pembayaran melalui portal pelanggan: {{portal_url}}\nID login: {{portal_username}}\n\nSetelah pembayaran diterima, layanan akan diproses untuk aktif kembali.\nFIKSUM",
        ],
    ];

    public function up(): void
    {
        $this->replaceTemplates(0, 1);
    }

    public function down(): void
    {
        $this->replaceTemplates(1, 0);
    }

    private function replaceTemplates(int $from, int $to): void
    {
        if (! Schema::hasTable('wa_templates')) {
            return;
        }

        foreach ($this->templates as $event => $bodies) {
            DB::table('wa_templates')->where('event', $event)->where('body', $bodies[$from])->update([
                'body' => $bodies[$to],
                'updated_at' => now(),
            ]);
        }
    }
};

