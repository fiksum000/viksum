<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function body(): string
    {
        return "Pesan otomatis. Nomor ini tidak bisa menerima telepon.\n\n"
            ."Tagihan Baru\n\n"
            ."Invoice No: {invoice_code}\n"
            ."Product: {profile_name}\n"
            ."Status: Belum Dibayar\n"
            ."Device: {name} - {user_code}\n\n"
            ."Jumlah Tagihan: {next_bill}\n"
            ."Tanggal Jatuh Tempo: {expired_at}\n\n"
            ."Anda dapat melihat rincian dan melakukan pembayaran melalui tautan berikut:\n"
            ."{link_payment}\n\n"
            ."Nomor ini tidak bisa menerima pesan atau telepon.\n\n"
            ."KELUHAN DAN SARAN CHAT 0859102797611\n\n"
            ."Mohon segera lakukan pembayaran sebelum tanggal jatuh tempo agar Anda tetap dapat menikmati layanan kami. Terima kasih.\n\n"
            ."Salam hormat,\nPT Usaha Adi Sanggoro";
    }

    private function legacyBody(): string
    {
        return "Yth. {{name}},\n\nKami mengingatkan tagihan internet Anda:\nNo. invoice: {{invoice_number}}\nID pelanggan: {{customer_code}}\nUsername layanan: {{service_username}}\nJumlah tagihan: Rp {{amount}}\nJatuh tempo: {{due_date}}\n\nPembayaran: {{payment_url}}\nPortal pelanggan: {{portal_url}}\nID login: {{portal_username}}\n\nTerima kasih.\nFIKSUM";
    }

    public function up(): void
    {
        if (! Schema::hasTable('wa_templates')) {
            return;
        }

        $now = now();
        $values = [
            'name' => 'Tagihan Baru',
            'body' => $this->body(),
            'updated_at' => $now,
        ];

        // Update only an existing row; template creation for fresh installs belongs to the seeder.
        // Preserve the operator's enabled/disabled preference.
        DB::table('wa_templates')->where('event', 'billing_reminder')->update($values);
    }

    public function down(): void
    {
        if (! Schema::hasTable('wa_templates')) {
            return;
        }

        // Revert only while this exact template is still present; preserve later admin edits.
        DB::table('wa_templates')
            ->where('event', 'billing_reminder')
            ->where('name', 'Tagihan Baru')
            ->where('body', $this->body())
            ->update([
                'name' => 'Pengingat tagihan',
                'body' => $this->legacyBody(),
                'updated_at' => now(),
            ]);
    }
};
