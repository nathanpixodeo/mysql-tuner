<?php

declare(strict_types=1);

namespace MySQLTuner\Tests\Unit\Collector;

use MySQLTuner\Collector\SystemCollector;
use PHPUnit\Framework\TestCase;

final class SystemCollectorTest extends TestCase
{
    private string $procRoot = '';

    protected function setUp(): void
    {
        $this->procRoot = sys_get_temp_dir() . '/mysql-tuner-proc-' . uniqid('', true);
        mkdir($this->procRoot);

        file_put_contents($this->procRoot . '/meminfo', implode("\n", [
            'MemTotal:        8123456 kB',
            'MemFree:          512000 kB',
            'MemAvailable:    4096000 kB',
        ]) . "\n");

        file_put_contents($this->procRoot . '/loadavg', "0.52 0.41 0.38 1/512 12345\n");

        // Eight cores. The first entry has no preceding newline, which is exactly what the old
        // substr_count("\nprocessor\t:") approach missed.
        $cpu = '';
        for ($i = 0; $i < 8; $i++) {
            $cpu .= "processor\t: {$i}\nmodel name\t: Test CPU\n\n";
        }
        file_put_contents($this->procRoot . '/cpuinfo', $cpu);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->procRoot . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->procRoot)) {
            rmdir($this->procRoot);
        }
    }

    /** @return array<string, mixed> */
    private function collect(bool $isLocal = true): array
    {
        return (new SystemCollector(
            isLocal: $isLocal,
            procRoot: $this->procRoot,
            diskPath: sys_get_temp_dir(),
        ))->collect();
    }

    public function testCpuCoreCountIncludesTheFirstProcessor(): void
    {
        self::assertSame(8, $this->collect()['cpu_cores']);
    }

    public function testTotalMemoryIsReadInBytes(): void
    {
        self::assertSame(8123456 * 1024, $this->collect()['total_memory']);
    }

    public function testAvailableMemoryIsReadInBytes(): void
    {
        self::assertSame(4096000 * 1024, $this->collect()['available_memory']);
    }

    public function testLoadAverageIsParsedAsThreeFloats(): void
    {
        $load = $this->collect()['load_avg'];

        self::assertSame([0.52, 0.41, 0.38], $load);
    }

    /**
     * Against a remote server these figures describe the wrong machine, so they are withheld
     * rather than reported and later turned into buffer-pool advice.
     */
    public function testRemoteConnectionReportsNoHostMetrics(): void
    {
        $result = $this->collect(isLocal: false);

        self::assertFalse($result['is_local']);
        self::assertArrayNotHasKey('total_memory', $result);
        self::assertArrayNotHasKey('cpu_cores', $result);
    }

    public function testMissingProcFilesDegradeToNull(): void
    {
        unlink($this->procRoot . '/meminfo');
        unlink($this->procRoot . '/cpuinfo');

        $result = $this->collect();

        self::assertNull($result['total_memory']);
        self::assertNull($result['cpu_cores']);
    }

    public function testFormatBytes(): void
    {
        self::assertSame('512B', SystemCollector::formatBytes(512));
        self::assertSame('1.00K', SystemCollector::formatBytes(1024));
        self::assertSame('1.00M', SystemCollector::formatBytes(1048576));
        self::assertSame('1.00G', SystemCollector::formatBytes(1073741824));
    }
}
