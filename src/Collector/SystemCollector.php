<?php

declare(strict_types=1);

namespace MySQLTuner\Collector;

/**
 * Host metrics for the machine running this process.
 *
 * These only describe the database server when the connection is local, so the caller must say so.
 * Against a remote host the collector reports nothing rather than let the analyzer size a buffer
 * pool from the operator's laptop.
 */
final class SystemCollector implements CollectorInterface
{
    public function __construct(
        private readonly bool $isLocal = true,
        private readonly string $procRoot = '/proc',
        private readonly ?string $diskPath = null,
    ) {}

    public function collect(): array
    {
        if (!$this->isLocal) {
            return [
                'os_type' => PHP_OS_FAMILY,
                'php_version' => PHP_VERSION,
                'is_local' => false,
            ];
        }

        $diskPath = $this->diskPath ?? sys_get_temp_dir();
        [$diskTotal, $diskFree] = $this->getDiskSpace($diskPath);

        return [
            'os_type' => PHP_OS_FAMILY,
            'php_version' => PHP_VERSION,
            'is_local' => true,
            'total_memory' => $this->getTotalMemory(),
            'available_memory' => $this->getAvailableMemory(),
            'load_avg' => $this->getLoadAvg(),
            'cpu_cores' => $this->getCpuCores(),
            'disk_path' => $diskPath,
            'disk_total' => $diskTotal,
            'disk_free' => $diskFree,
            'disk_used_percent' => $this->usedPercent($diskTotal, $diskFree),
        ];
    }

    private function getTotalMemory(): ?int
    {
        return $this->readMemInfo('MemTotal');
    }

    private function getAvailableMemory(): ?int
    {
        return $this->readMemInfo('MemAvailable');
    }

    private function readMemInfo(string $field): ?int
    {
        $content = $this->readProc('meminfo');

        if ($content === null) {
            return null;
        }

        if (preg_match('/^' . preg_quote($field, '/') . ':\s+(\d+)\s+kB/m', $content, $m) === 1) {
            return (int) $m[1] * 1024;
        }

        return null;
    }

    /** @return list<float>|null */
    private function getLoadAvg(): ?array
    {
        $content = $this->readProc('loadavg');

        if ($content === null) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($content)) ?: [];

        return [
            (float) ($parts[0] ?? 0),
            (float) ($parts[1] ?? 0),
            (float) ($parts[2] ?? 0),
        ];
    }

    /**
     * Physical/logical core count from /proc/cpuinfo.
     *
     * Counted with a multiline regex rather than substr_count on "\nprocessor": the first entry has
     * no preceding newline, so that approach reported one core fewer than the machine has, and it
     * also assumed a tab between the key and the colon.
     */
    private function getCpuCores(): ?int
    {
        $content = $this->readProc('cpuinfo');

        if ($content === null) {
            return null;
        }

        $count = preg_match_all('/^processor\s*:/mi', $content);

        return is_int($count) && $count > 0 ? $count : null;
    }

    private const DEFAULT_PROC_ROOT = '/proc';

    private function readProc(string $file): ?string
    {
        // /proc is Linux-only. There is deliberately no shell fallback: the previous `nproc` call
        // ran outside this guard, spawning a doomed cmd.exe on Windows on every single run.
        // An explicitly supplied root is honoured anywhere, which is what makes the parsing
        // testable on the platform this is often developed on.
        if (PHP_OS_FAMILY !== 'Linux' && $this->procRoot === self::DEFAULT_PROC_ROOT) {
            return null;
        }

        $path = $this->procRoot . '/' . $file;

        if (!is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        // file_exists does not imply readable: containers routinely mask parts of /proc.
        return is_string($content) ? $content : null;
    }

    /** @return array{0: ?float, 1: ?float} */
    private function getDiskSpace(string $path): array
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        return [
            is_float($total) && $total > 0 ? $total : null,
            is_float($free) ? $free : null,
        ];
    }

    private function usedPercent(?float $total, ?float $free): ?float
    {
        if ($total === null || $free === null || $total <= 0.0) {
            return null;
        }

        return round(($total - $free) / $total * 100, 1);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.2fG', $bytes / 1073741824);
        }
        if ($bytes >= 1048576) {
            return sprintf('%.2fM', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.2fK', $bytes / 1024);
        }

        return "{$bytes}B";
    }
}
