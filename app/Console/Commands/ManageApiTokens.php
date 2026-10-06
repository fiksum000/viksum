<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ManageApiTokens extends Command
{
    protected $signature = 'billing:api-token {email? : Email super admin/admin} {name? : Label integrasi} {--days=365 : Masa berlaku token; 0 berarti tanpa kedaluwarsa} {--revoke= : ID token yang akan dicabut}';
    protected $description = 'Buat token API baca-saja atau cabut token yang sudah ada';

    public function handle(): int
    {
        if ($revoke = $this->option('revoke')) {
            $token = ApiToken::find($revoke);
            if (! $token) { $this->error('Token tidak ditemukan.'); return self::FAILURE; }
            $token->delete();
            $this->info("Token #{$revoke} sudah dicabut.");
            return self::SUCCESS;
        }

        $user = User::where('email', $this->argument('email'))->first();
        if (! $user || ! in_array($user->role, ['super_admin', 'admin'], true)) {
            $this->error('Email harus milik pengguna super_admin atau admin yang ada.');
            return self::FAILURE;
        }
        $name = trim((string) $this->argument('name'));
        if ($name === '' || mb_strlen($name) > 100) { $this->error('Berikan nama integrasi 1–100 karakter.'); return self::FAILURE; }
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 0 || $days > 3650) { $this->error('Masa berlaku harus 0–3650 hari.'); return self::FAILURE; }

        $secret = Str::random(60);
        $token = ApiToken::create(['user_id' => $user->id, 'name' => $name, 'token_hash' => hash('sha256', $secret), 'abilities' => ['read'], 'expires_at' => $days === 0 ? null : now()->addDays($days)]);
        $this->newLine();
        $this->warn('Salin token ini sekarang. Token utuh tidak disimpan dan tidak bisa ditampilkan kembali:');
        $this->line($token->id.'|'.$secret);
        $this->newLine();
        $this->info('Izin: baca-saja. Cabut dengan: php artisan billing:api-token --revoke='.$token->id);

        return self::SUCCESS;
    }
}
