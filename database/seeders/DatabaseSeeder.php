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
            ['event' => 'billing_reminder', 'name' => 'Pengingat tagihan', 'body' => Yth. {{name}}, kami mengingatkan tagihan {{invoice_number}} sebesar Rp {{amount}} yang jatuh tempo pada {{due_date}}. ID pelanggan: {{customer_code}}. Username layanan: {{service_username}}. Silakan lakukan pembayaran melalui {{payment_url}}. Portal pelanggan: {{portal_url}} (ID login: {{portal_username}}). Terima kasih.],
            ['event' => 'isolation_warning', 'name' => 'Peringatan H-1 isolir', 'body' => Yth. {{name}}, tagihan {{invoice_number}} sebesar Rp {{amount}} belum kami terima. Mohon lakukan pembayaran sebelum {{isolation_date}} agar layanan tidak diisolir. ID pelanggan: {{customer_code}}. Username layanan: {{service_username}}. Pembayaran: {{payment_url}}. Portal pelanggan: {{portal_url}} (ID login: {{portal_username}}). Terima kasih.],
            ['event' => 'payment_success', 'name' => 'Pembayaran berhasil', 'body' => Yth. {{name}}, pembayaran {{invoice_number}} sebesar Rp {{amount}} telah kami terima. Terima kasih telah melakukan pembayaran. ID pelanggan: {{customer_code}}. Username layanan: {{service_username}}. Portal pelanggan: {{portal_url}} (ID login: {{portal_username}}).],
            ['event' => 'isolation', 'name' => 'Layanan diisolir', 'body' => Yth. {{name}}, layanan internet Anda telah diisolir sementara karena tagihan belum dibayar. Silakan selesaikan pembayaran agar layanan dapat dipulihkan. ID pelanggan: {{customer_code}}. Username layanan: {{service_username}}. Portal pelanggan: {{portal_url}} (ID login: {{portal_username}}). Terima kasih.],
            ['event' => 'broadcast', 'name' => 'Pesan broadcast', 'body' => '{{message}}'],
        ] as $template) {
            WaTemplate::firstOrCreate(['event' => $template['event']], $template + ['enabled' => true]);
        }
    }
}

