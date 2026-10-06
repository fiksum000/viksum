<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use ZipArchive;

class CreateEncryptedBackup extends Command
{
    protected $signature = 'billing:backup';
    protected $description = 'Buat backup database dan konfigurasi terenkripsi';

    public function handle(): int
    {
        $key = (string) config('app.backup_encryption_key', '');
        if ($key === '') {
            $this->error('BACKUP_ENCRYPTION_KEY belum diatur. Backup tidak dibuat tanpa enkripsi.');
            return self::FAILURE;
        }
        $database = config('database.connections.mysql');
        if (!$database || !in_array($database['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error('Backup saat ini hanya mendukung MariaDB/MySQL.');
            return self::FAILURE;
        }

        $directory = storage_path('app/private/backups');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            $this->error('Folder backup tidak dapat dibuat.');
            return self::FAILURE;
        }
        $stamp = now(config('billing.timezone'))->format('Ymd-His');
        $sqlPath = $directory.'/'.$stamp.'.sql';
        $zipPath = $directory.'/'.$stamp.'.zip';
        $encryptedPath = $directory.'/'.$stamp.'.zip.enc';

        try {
            $sql = fopen($sqlPath, 'wb');
            if (!$sql) throw new \RuntimeException('File SQL sementara tidak dapat dibuat.');
            chmod($sqlPath, 0600);

            $dump = new Process([
                'mariadb-dump', '--single-transaction', '--routines', '--triggers',
                '--host='.$database['host'], '--port='.(string) $database['port'],
                '--user='.$database['username'], $database['database'],
            ], base_path(), ['MYSQL_PWD' => $database['password']]);
            $dump->setTimeout(1800);
            $dump->run(function (string $type, string $buffer) use ($sql): void {
                if ($type === Process::OUT) fwrite($sql, $buffer);
                if ($type === Process::ERR && $buffer !== '') $this->output->writeln('<comment>'.trim($buffer).'</comment>');
            });
            fclose($sql);
            if (!$dump->isSuccessful()) throw new \RuntimeException('mariadb-dump gagal: '.$dump->getErrorOutput());

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Arsip backup gagal dibuat.');
            $zip->addFile($sqlPath, 'database.sql');
            if (is_file(base_path('.env'))) $zip->addFile(base_path('.env'), '.env');
            $this->addStorageFiles($zip);
            $zip->addFromString('BACKUP-METADATA.txt', "Created: ".now(config('billing.timezone'))->toIso8601String()."\nApplication: Billing RTRW Net\n");
            $zip->close();
            chmod($zipPath, 0600);

            $encrypt = new Process(['openssl', 'enc', '-aes-256-cbc', '-salt', '-pbkdf2', '-iter', '200000', '-in', $zipPath, '-out', $encryptedPath, '-pass', 'env:BACKUP_ENCRYPTION_KEY'], base_path(), ['BACKUP_ENCRYPTION_KEY' => $key]);
            $encrypt->setTimeout(1800);
            $encrypt->run();
            if (!$encrypt->isSuccessful()) throw new \RuntimeException('Enkripsi backup gagal: '.$encrypt->getErrorOutput());
            chmod($encryptedPath, 0600);
            $this->pruneBackups($directory);
            $this->info('Backup terenkripsi tersimpan: '.$encryptedPath);
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            @unlink($encryptedPath);
            $this->error($exception->getMessage());
            return self::FAILURE;
        } finally {
            @unlink($sqlPath);
            @unlink($zipPath);
        }
    }

    private function addStorageFiles(ZipArchive $zip): void
    {
        $root = storage_path('app');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink() || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR)) continue;
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $zip->addFile($file->getPathname(), 'storage/app/'.str_replace(DIRECTORY_SEPARATOR, '/', $relative));
        }
    }

    private function pruneBackups(string $directory): void
    {
        $retentionDays = max(1, (int) env('BACKUP_RETENTION_DAYS', 14));
        foreach (glob($directory.'/*.zip.enc') ?: [] as $backup) {
            if (filemtime($backup) < now()->subDays($retentionDays)->getTimestamp()) @unlink($backup);
        }
    }
}
