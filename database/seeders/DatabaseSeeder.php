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
            ['event' => 'billing_reminder', 'name' => 'Pengingat tagihan', 'body' => 'Halo {{name}}, tagihan {{invoice_number}} sebesar Rp {{amount}} jatuh tempo {{due_date}}. Bayar: {{payment_url}}'],
            ['event' => 'isolation_warning', 'name' => 'Peringatan H-1 isolir', 'body' => 'Halo {{name}}, tagihan {{invoice_number}} sebesar Rp {{amount}} belum dibayar. Layanan internet akan diisolir pada {{isolation_date}} jika pembayaran belum diterima. Bayar: {{payment_url}}'],
            ['event' => 'payment_success', 'name' => 'Pembayaran berhasil', 'body' => 'Halo {{name}}, pembayaran {{invoice_number}} sebesar Rp {{amount}} berhasil kami terima. Terima kasih.'],
            ['event' => 'isolation', 'name' => 'Layanan diisolir', 'body' => 'Halo {{name}}, layanan internet diisolir karena tagihan belum dibayar. Silakan lakukan pembayaran untuk aktivasi kembali.'],
            ['event' => 'broadcast', 'name' => 'Pesan broadcast', 'body' => '{{message}}'],
        ] as $template) {
            WaTemplate::firstOrCreate(['event' => $template['event']], $template + ['enabled' => true]);
        }
    }
}

