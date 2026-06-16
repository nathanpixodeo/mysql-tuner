<?php

namespace MySQLTuner\Collector;

class SystemCollector implements CollectorInterface
{
    public function collect(): array
    {
        return [
            'os_type' => PHP_OS_FAMILY,
            'php_version' => PHP_VERSION,
            'total_memory' => $this->getTotalMemory(),
            'available_memory' => $this->getAvailableMemory(),
            'load_avg' => $this->getLoadAvg(),
            'cpu_cores' => $this->getCpuCores(),
            'disk_total' => $this->getDiskSpace('total'),
            'disk_free' => $this->getDiskSpace('free'),
            'disk_used_percent' => $this->getDiskUsedPercent(),
        ];
    }

    private function getTotalMemory(): ?int
    {
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/proc/meminfo')) {
            $content = file_get_contents('/proc/meminfo');
            if (preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $content, $m)) {
                return (int) $m[1] * 1024;
            }
        }
        return null;
    }

    private function getAvailableMemory(): ?int
    {
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/proc/meminfo')) {
            $content = file_get_contents('/proc/meminfo');
            if (preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $content, $m)) {
                return (int) $m[1] * 1024;
            }
        }
        return null;
    }

    /** @return array<float>|null */
    /** @return list<float>|null */
    private function getLoadAvg(): ?array
    {
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/proc/loadavg')) {
            $parts = explode(' ', trim(file_get_contents('/proc/loadavg')));
            return [(float) ($parts[0] ?? 0), (float) ($parts[1] ?? 0), (float) ($parts[2] ?? 0)];
        }
        return null;
    }

    private function getCpuCores(): ?int
    {
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/proc/cpuinfo')) {
            return substr_count(file_get_contents('/proc/cpuinfo'), "\nprocessor\t:");
        }

        $cores = (int) shell_exec('nproc 2>/dev/null');
        return $cores > 0 ? $cores : null;
    }

    private function getDiskSpace(string $type): ?float
    {
        $path = sys_get_temp_dir();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        return match ($type) {
            'total' => $total ?: null,
            'free' => $free ?: null,
            default => null,
        };
    }

    private function getDiskUsedPercent(): ?float
    {
        $total = $this->getDiskSpace('total');
        $free = $this->getDiskSpace('free');

        if ($total === null || $free === null || $total === 0.0) {
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
