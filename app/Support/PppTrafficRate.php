<?php

namespace App\Support;

final class PppTrafficRate
{
    /** @return array{download_bps:int, upload_bps:int}|null */
    public static function fromSamples(array $previous, array $current): ?array
    {
        $elapsed = (int) ($current['sampled_at'] ?? 0) - (int) ($previous['sampled_at'] ?? 0);
        $previousDownload = $previous['download_bytes'] ?? null;
        $previousUpload = $previous['upload_bytes'] ?? null;
        $currentDownload = $current['download_bytes'] ?? null;
        $currentUpload = $current['upload_bytes'] ?? null;

        if ($elapsed < 5 || $elapsed > 120
            || !is_numeric($previousDownload) || !is_numeric($previousUpload)
            || !is_numeric($currentDownload) || !is_numeric($currentUpload)
            || $currentDownload < $previousDownload || $currentUpload < $previousUpload) {
            return null;
        }

        return [
            'download_bps' => (int) round((($currentDownload - $previousDownload) * 8) / $elapsed),
            'upload_bps' => (int) round((($currentUpload - $previousUpload) * 8) / $elapsed),
        ];
    }
}
