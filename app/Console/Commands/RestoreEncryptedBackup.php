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
            stream_copy_to_stream($sql, $output);
            fclose($sql);
            fclose($output);
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
            $this->removeTemporaryDirectory($temporary);
        }
    }

    private function restoreStorageFiles(string $zipPath, array $entries): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) throw new \RuntimeException('Arsip tidak dapat dibuka lagi untuk pemulihan file.');
        foreach ($entries as [$entry, $relative]) {
            $destination = base_path($relative);
            $directory = dirname($destination);
            $this->assertNoSymlinkParents(storage_path('app'), $directory);
            if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) { $zip->close(); throw new \RuntimeException('Folder storage gagal dibuat.'); }
            if (is_link($destination)) { $zip->close(); throw new \RuntimeException('Restore menolak target symlink di storage.'); }
            $contents = $zip->getStream($entry);
            $target = fopen($destination, 'wb');
            if (! is_resource($contents) || ! $target) { if (is_resource($contents)) fclose($contents); if ($target) fclose($target); $zip->close(); throw new \RuntimeException('Gagal memulihkan file '.$entry); }
            stream_copy_to_stream($contents, $target);
            fclose($contents);
            fclose($target);
        }
        $zip->close();
    }

    private function assertNoSymlinkParents(string $root, string $directory): void
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($directory !== $root && ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR)) throw new \RuntimeException('Restore menolak path di luar storage/app.');
        $cursor = $root;
        foreach (array_filter(explode(DIRECTORY_SEPARATOR, substr($directory, strlen($root) + 1))) as $part) {
            $cursor .= DIRECTORY_SEPARATOR.$part;
            if (is_link($cursor)) throw new \RuntimeException('Restore menolak direktori symlink di storage.');
        }
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $item) {
            if (is_dir($item) && ! is_link($item)) $this->removeTemporaryDirectory($item);
            else @unlink($item);
        }
        @rmdir($directory);
    }
}
