<?php

declare(strict_types=1);

$path = getcwd().'/.env';
$contents = file_get_contents($path);
if ($contents === false) {
    fwrite(STDERR, "Could not read .env\n");
    exit(1);
}

$keys = ['APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_TIMEZONE', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION', 'ADMIN_NAME', 'ADMIN_EMAIL', 'ADMIN_PASSWORD', 'BACKUP_ENCRYPTION_KEY'];
foreach ($keys as $key) {
    $value = getenv($key);
    if ($value === false) continue;
    $escaped = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $value);
    $line = $key.'="'.$escaped.'"';
    $pattern = '/^'.preg_quote($key, '/').'=.*/m';
    if (preg_match($pattern, $contents)) {
        $contents = preg_replace_callback($pattern, static fn (): string => $line, $contents, 1);
    } else {
        $contents .= "\n".$line;
    }
}

if (file_put_contents($path, rtrim($contents)."\n", LOCK_EX) === false) {
    fwrite(STDERR, "Could not write .env\n");
    exit(1);
}
