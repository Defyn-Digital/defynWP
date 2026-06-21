<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

final class ReportStorage
{
    private const SUBDIR = 'defyn-reports';

    public function dir(): string
    {
        $up = wp_upload_dir();
        return rtrim($up['basedir'], '/') . '/' . self::SUBDIR;
    }

    public function ensureDir(): void
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        // Best-effort deny for Apache hosts (ignored on nginx/Kinsta — the random
        // filename + authed-only serving is the real protection).
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "Deny from all\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a plugin-private guard file
        }
        $idx = $dir . '/index.html';
        if (!file_exists($idx)) {
            @file_put_contents($idx, ''); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a plugin-private guard file
        }
    }

    /** @return array{file_name:string,size:int} */
    public function store(int $reportId, string $bytes): array
    {
        $this->ensureDir();
        $token = wp_generate_password(32, false, false);
        $name  = "report-{$reportId}-{$token}.pdf";
        file_put_contents($this->path($name), $bytes); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a plugin-private report file
        return ['file_name' => $name, 'size' => strlen($bytes)];
    }

    public function path(string $fileName): string
    {
        return $this->dir() . '/' . basename($fileName);
    }

    public function read(string $fileName): ?string
    {
        $p = $this->path($fileName);
        if (!is_file($p)) {
            return null;
        }
        $bytes = file_get_contents($p); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin-private report file
        return $bytes === false ? null : $bytes;
    }

    public function delete(string $fileName): void
    {
        $p = $this->path($fileName);
        if (is_file($p)) {
            @unlink($p); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing a plugin-private file
        }
    }
}
