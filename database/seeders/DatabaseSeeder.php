<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\User;
use App\Models\WaTemplate;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) env('ADMIN_EMAIL'));
        $password = (string) env('ADMIN_PASSWORD');
        if ($email === '' || $password === '' || $password === 'change-this-now') {
            throw new RuntimeException('Set a unique ADMIN_EMAIL and a strong ADMIN_PASSWORD in .env before running db:seed.');
        }

        User::updateOrCreate(
            ['email' => $email],
            ['name' => env('ADMIN_NAME', 'Super Admin'), 'password' => $password, 'role' => 'super_admin'],
        );

        Package::firstOrCreate(['name' => '10 Mbps'], ['price' => 150000, 'normal_profile' => '10M', 'normal_speed' => '10 Mbps', 'fup_enabled' => true, 'fup_limit_bytes' => 322122547200, 'fup_speed_after' => '3M', 'priority' => 8]);
        Package::firstOrCreate(['name' => '20 Mbps'], ['price' => 200000, 'normal_profile' => '20M', 'normal_speed' => '20 Mbps', 'fup_enabled' => true, 'fup_limit_bytes' => 536870912000, 'fup_speed_after' => '5M', 'priority' => 8]);

        foreach ([
            ['event' => 'billing_reminder', 'name' => 'Pengingat tagihan', 'body' => "Yth. {{name}},\n\nKami mengingatkan tagihan internet Anda:\nNo. invoice: {{invoice_number}}\nID pelanggan: {{customer_code}}\nUsername layanan: {{service_username}}\nJumlah tagihan: Rp {{amount}}\nJatuh tempo: {{due_date}}\n\nPembayaran: {{payment_url}}\nPortal pelanggan: {{portal_url}}\nID login: {{portal_username}}\n\nTerima kasih.\nFIKSUM"],
            ['event' => 'isolation_warning', 'name' => 'Peringatan H-1 isolir', 'body' => "Yth. {{name}},\n\nTagihan internet Anda belum kami terima:\nNo. invoice: {{invoice_number}}\nID pelanggan: {{customer_code}}\nUsername layanan: {{service_username}}\nJumlah tagihan: Rp {{amount}}\nBatas pembayaran: {{isolation_date}}\n\nPembayaran: {{payment_url}}\nPortal pelanggan: {{portal_url}}\nID login: {{portal_username}}\n\nMohon lakukan pembayaran sebelum batas waktu agar layanan tidak diisolir.\nFIKSUM"],
            ['event' => 'payment_success', 'name' => 'Pembayaran berhasil', 'body' => "Yth. {{name}},\n\nKonfirmasi pembayaran\nNo. invoice: {{invoice_number}}\nStatus: Lunas\nID pelanggan: {{customer_code}}\nUsername layanan: {{service_username}}\nJumlah dibayar: Rp {{amount}}\n\nPortal pelanggan: {{portal_url}}\nID login: {{portal_username}}\n\nTerima kasih atas pembayaran Anda.\nFIKSUM"],
            ['event' => 'isolation', 'name' => 'Layanan diisolir', 'body' => "Yth. {{name}},\n\nLayanan internet Anda sedang diisolir karena tagihan belum dibayar.\nID pelanggan: {{customer_code}}\nUsername layanan: {{service_username}}\n\nSelesaikan pembayaran melalui portal pelanggan: {{portal_url}}\nID login: {{portal_username}}\n\nSetelah pembayaran diterima, layanan akan diproses untuk aktif kembali.\nFIKSUM"],
            ['event' => 'broadcast', 'name' => 'Pesan broadcast', 'body' => '{{message}}'],
        ] as $template) {
            WaTemplate::firstOrCreate(['event' => $template['event']], $template + ['enabled' => true]);
        }
    }
}


