<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use ZipArchive;

class RestoreEncryptedBackup extends Command
{
    protected $signature = 'billing:restore {file : Nama file .zip.enc dari storage/app/private/backups} {--force : Jalankan tanpa prompt konfirmasi}';
    protected $description = 'Pulihkan database dan file storage dari backup terenkripsi';

    public function handle(): int
    {
        $name = (string) $this->argument('file');
        if (! preg_match('/^\d{8}-\d{6}\.zip\.enc$/', $name)) { $this->error('Gunakan nama file backup berbentuk YYYYMMDD-HHMMSS.zip.enc.'); return self::FAILURE; }
        $backup = storage_path('app/private/backups/'.$name);
        if (! is_file($backup) || is_link($backup)) { $this->error('File backup tidak ditemukan.'); return self::FAILURE; }
        $key = (string) config('app.backup_encryption_key', '');
        $database = config('database.connections.mysql');
        if ($key === '' || ! $database || ! in_array($database['driver'] ?? null, ['mysql', 'mariadb'], true)) { $this->error('Kunci backup atau konfigurasi MariaDB/MySQL belum tersedia.'); return self::FAILURE; }
        $macPath = $backup.'.mac';
        if (! is_file($macPath) || is_link($macPath)) { $this->error('File pemeriksaan integritas .mac tidak ditemukan; backup lama tanpa MAC tidak bisa dipulihkan dengan aman.'); return self::FAILURE; }
        $providedMac = trim((string) file_get_contents($macPath));
        $macKey = hash_hmac('sha256', 'billing-rtrwnet-backup-integrity', $key, true);
        $expectedMac = hash_hmac_file('sha256', $backup, $macKey);
        if (! $expectedMac || ! hash_equals($expectedMac, $providedMac)) { $this->error('Pemeriksaan integritas backup gagal.'); return self::FAILURE; }
        if (! extension_loaded('zip')) { $this->error('Ekstensi PHP zip belum terpasang.'); return self::FAILURE; }

        $temporary = storage_path('framework/backup-restore/'.bin2hex(random_bytes(8)));
        if (! mkdir($temporary, 0700, true) && ! is_dir($temporary)) { $this->error('Folder sementara restore tidak bisa dibuat.'); return self::FAILURE; }
        $zipPath = $temporary.'/restore.zip';
        $sqlPath = $temporary.'/database.sql';

        try {
            $decrypt = new Process(['openssl', 'enc', '-d', '-aes-256-cbc', '-salt', '-pbkdf2', '-iter', '200000', '-in', $backup, '-out', $zipPath, '-pass', 'env:BACKUP_ENCRYPTION_KEY'], base_path(), ['BACKUP_ENCRYPTION_KEY' => $key]);
            $decrypt->setTimeout(1800);
            $decrypt->run();
            if (! $decrypt->isSuccessful()) throw new \RuntimeException('Dekripsi gagal. Periksa file backup dan BACKUP_ENCRYPTION_KEY.');

            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) throw new \RuntimeException('Arsip backup tidak valid.');
            $sql = $zip->getStream('database.sql');
            if (! is_resource($sql)) { $zip->close(); throw new \RuntimeException('Arsip tidak berisi dump database.'); }
            $prefix = fread($sql, 65536);
            rewind($sql);
            $output = fopen($sqlPath, 'wb');
            if (! $output || ! str_contains((string) $prefix, 'CREATE TABLE')) { fclose($sql); if ($output) fclose($output); $zip->close(); throw new \RuntimeException('Dump database tidak dikenali atau tidak bisa ditulis.'); }
            $copied = stream_copy_to_stream($sql, $output);
            fclose($sql);
            fclose($output);
            if ($copied === false) { $zip->close(); throw new \RuntimeException('Gagal mengekstrak dump SQL dari backup.'); }
            chmod($sqlPath, 0600);

            $storageEntries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->getNameIndex($index);
                if (! is_string($entry) || ! str_starts_with($entry, 'storage/app/')) continue;
                $relative = substr($entry, strlen('storage/app/'));
                if ($relative === '' || str_contains($relative, '\\') || in_array('..', explode('/', $relative), true) || str_starts_with($relative, '/')) continue;
                $storageEntries[] = [$entry, 'storage/app/'.$relative];
            }
            $zip->close();

            $this->warn('Restore mengganti isi database saat ini dan menimpa file dengan nama yang sama di storage/app. .env saat ini tetap dipakai.');
            if (! $this->option('force') && ! $this->confirm('Lanjutkan? Backup pengaman database akan dibuat lebih dulu.')) return self::SUCCESS;

            if (Artisan::call('billing:backup') !== self::SUCCESS) throw new \RuntimeException('Backup pengaman gagal dibuat; restore dibatalkan.');
            $this->line(trim(Artisan::output()));

            $input = fopen($sqlPath, 'rb');
            if (! $input) throw new \RuntimeException('Dump SQL gagal dibuka untuk restore.');
            try {
                $restore = new Process(['mariadb', '--host='.$database['host'], '--port='.(string) $database['port'], '--user='.$database['username'], $database['database']], base_path(), ['MYSQL_PWD' => $database['password']]);
                $restore->setInput($input);
                $restore->setTimeout(3600);
                $restore->run();
                if (! $restore->isSuccessful()) throw new \RuntimeException('Restore database gagal: '.trim($restore->getErrorOutput()));
            } finally {
                fclose($input);
            }

            $this->restoreStorageFiles($zipPath, $storageEntries);
            $this->info('Restore selesai. Jalankan php artisan optimize:clear bila konfigurasi Laravel perlu dimuat ulang.');
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());
            return self::FAILURE;
        } finally {
            $this->removeTem}üÒÚ$z{-®éÜj×TW†6WF–öâ‚uF–F²&—6ÖVçVÆ—2f–ÆRrâFVçG'’âr¶R7F÷&vRâr“²Ð¢F6÷–VBÒ7G&VÕö6÷•÷Fõ÷7G&VÒ‚F6öçFVçG2ÂGF&vWB“°¢f6Æ÷6R‚F6öçFVçG2“°¢f6Æ÷6R‚GF&vWB“°¢–b‚F6÷–VBÓÓÒfÇ6R’²G¦—Óæ6Æ÷6R‚“²F‡&÷ræWrÅ'VçF–ÖTW†6WF–öâ‚tvvÂÖV×VÆ–†¶âf–ÆRrâFVçG'’“²Ð¢Ð¢G¦—Óæ6Æ÷6R‚“°¢Ð ¢&—fFRgVæ7F–öâ76W'Dæõ7–ÖÆ–æµ&VçG2‡7G&–ærG&ö÷BÂ7G&–ærFF—&V7F÷'’“¢fö–@¢°¢G&ö÷BÒ'G&–Ò‚G&ö÷BÂD•$T5Dõ%•õ4U$Dõ"“°¢FF—&V7F÷'’Ò'G&–Ò‚FF—&V7F÷'’ÂD•$T5Dõ%•õ4U$Dõ"“°¢–b‚FF—&V7F÷'’ÓÒG&ö÷Bbb7G%÷7F'G5÷v—F‚‚FF—&V7F÷'’ÂG&ö÷BäD•$T5Dõ%•õ4U$Dõ"’’F‡&÷ræWrÅ'VçF–ÖTW†6WF–öâ‚u&W7F÷&RÖVæöÆ²F‚F’ÇV"7F÷&vRöâr“°¢F7W'6÷"ÒG&ö÷C°¢f÷&V6‚†'&•öf–ÇFW"†W‡ÆöFR„D•$T5Dõ%•õ4U$Dõ"Â7V'7G"‚FF—&V7F÷'’Â7G&ÆVâ‚G&ö÷B’²’’’2G'B’°¢F7W'6÷"ãÒD•$T5Dõ%•õ4U$Dõ"âG'C°¢–b†—5öÆ–æ²‚F7W'6÷"’’F‡&÷ræWrÅ'VçF–ÖTW†6WF–öâ‚u&W7F÷&RÖVæöÆ²F—&V·F÷&’7–ÖÆ–æ²F’7F÷&vRâr“°¢Ð¢Ð ¢&—fFRgVæ7F–öâ&VÖ÷fUFV×÷&'”F—&V7F÷'’‡7G&–ærFF—&V7F÷'’“¢fö–@¢°¢f÷&V6‚†vÆö"‚FF—&V7F÷'’ârò¢r’ó¢µÒ2F—FVÒ’°¢–b†—5öF—"‚F—FVÒ’bb—5öÆ–æ²‚F—FVÒ’’GF†—2Óç&VÖ÷fUFV×÷&'”F—&V7F÷'’‚F—FVÒ“°¢VÇ6RVæÆ–æ²‚F—FVÒ“°¢Ð¢&ÖF—"‚FF—&V7F÷'’“°¢Ð§Ð