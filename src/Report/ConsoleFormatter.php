<?php

declare(strict_types=1);

namespace MySQLTuner\Report;

use MySQLTuner\Recommendation\Recommendation;
use MySQLTuner\Recommendation\Severity;

class ConsoleFormatter
{
    private const COLORS = [
        'OK' => "\033[32m",
        'WARNING' => "\033[33m",
        'CRITICAL' => "\033[31m",
        'INFO' => "\033[36m",
        'RESET' => "\033[0m",
        'BOLD' => "\033[1m",
        'DIM' => "\033[2m",
    ];

    private const SECURITY_GROUP = 'security';

    private bool $useColors;

    public function __construct(
        private readonly bool $noColor = false,
    ) {
        $this->useColors = !$noColor
            && PHP_SAPI === 'cli'
            // Honour the NO_COLOR convention, and do not write escape sequences into a file or a
            // pipe: `mysql-tuner > report.txt` used to capture raw ANSI codes.
            && getenv('NO_COLOR') === false
            && (!function_exists('stream_isatty') || @stream_isatty(STDOUT));
    }

    public function format(Report $report): string
    {
        $lines = [];

        $lines[] = $this->header("MySQL Tuner Report");

        $lines[] = sprintf(
            "  %sVersion: %s%s",
            $this->color('INFO'),
            $report->version,
            $this->reset(),
        );
        $lines[] = sprintf(
            "  %sUptime: %s%s",
            $this->color('INFO'),
            self::formatUptime($report->uptime),
            $this->reset(),
        );

        $lines[] = sprintf(
            "  %sHealth Score: %s%d/100%s",
            $this->color('INFO'),
            $this->bold(),
            $report->score,
            $this->reset(),
        );

        $lines[] = '';

        if ($report->systemMetrics !== []) {
            $system = $report->systemMetrics;

            $lines[] = $this->section("System Information");
            $lines[] = $this->kv('OS', is_string($system['os_type'] ?? null) ? $system['os_type'] : 'N/A');

            if (($system['is_local'] ?? true) !== true) {
                // Say so explicitly rather than quietly omitting the rows below, which would read
                // as "this host has no memory worth reporting".
                $lines[] = $this->kv('Host', 'remote - local system metrics not applicable');
            }

            $totalMem = $system['total_memory'] ?? null;
            if (is_int($totalMem)) {
                $lines[] = $this->kv('Total Memory', $this->formatBytes($totalMem));
            }

            $load = $system['load_avg'] ?? null;
            if (is_array($load) && $load !== []) {
                $lines[] = $this->kv('Load Average', implode(', ', array_map(
                    static fn(mixed $v): string => sprintf('%.2f', is_numeric($v) ? (float) $v : 0.0),
                    $load,
                )));
            }

            $cores = $system['cpu_cores'] ?? null;
            if (is_int($cores)) {
                $lines[] = $this->kv('CPU Cores', (string) $cores);
            }

            $diskUsed = $system['disk_used_percent'] ?? null;
            if (is_float($diskUsed)) {
                $path = is_string($system['disk_path'] ?? null) ? $system['disk_path'] : '';
                $lines[] = $this->kv('Disk Used', sprintf('%.1f%%%s', $diskUsed, $path === '' ? '' : " ({$path})"));
            }

            $lines[] = '';
        }

        $this->renderSecuritySection($report, $lines);

        $lines[] = $this->section("Performance Recommendations");
        $lines[] = '';

        $grouped = $report->grouped();
        foreach ($grouped as $group => $recs) {
            // Already rendered above under "Security Issues". Without this skip every security
            // finding was printed a second time here.
            if ($group === self::SECURITY_GROUP) {
                continue;
            }

            $hasIssues = false;
            foreach ($recs as $rec) {
                if ($rec->severity !== Severity::OK) {
                    $hasIssues = true;
                    break;
                }
            }

            if (!$hasIssues) {
                continue;
            }

            $lines[] = $this->subSection($group);

            foreach ($recs as $rec) {
                $lines[] = $this->formatRecommendation($rec);
            }
            $lines[] = '';
        }

        $lines[] = str_repeat('─', 60);
        $lines[] = $this->summaryLine($report);
        $lines[] = str_repeat('─', 60);

        return implode("\n", $lines) . "\n";
    }

    /** @param list<string> &$lines */
    private function renderSecuritySection(Report $report, array &$lines): void
    {
        $securityRecs = $report->byGroup(self::SECURITY_GROUP);

        if ($securityRecs === []) {
            return;
        }

        $hasIssues = false;
        foreach ($securityRecs as $rec) {
            if ($rec->severity !== Severity::OK) {
                $hasIssues = true;
                break;
            }
        }

        if (!$hasIssues) {
            return;
        }

        $lines[] = $this->section("Security Issues");

        foreach ($securityRecs as $rec) {
            $lines[] = $this->formatRecommendation($rec);
        }
        $lines[] = '';
    }

    private function formatRecommendation(Recommendation $rec): string
    {
        $icon = match ($rec->severity) {
            Severity::OK => "[{$this->color('OK')}OK{$this->reset()}]",
            Severity::WARNING => "[{$this->color('WARNING')}!!{$this->reset()}]",
            Severity::CRITICAL => "[{$this->color('CRITICAL')}EE{$this->reset()}]",
            Severity::INFO => "[{$this->color('INFO')}--{$this->reset()}]",
        };

        $out = "  {$icon} {$rec->summary}";

        if ($rec->currentValue !== null) {
            $out .= " {$this->dim('(' . $rec->currentValue . ')')}";
        }

        if ($rec->suggestion !== null) {
            $out .= "\n       {$this->dim($rec->suggestion)}";
        }

        if ($rec->detail !== null) {
            $out .= "\n       {$this->dim($rec->detail)}";
        }

        return $out;
    }

    private function header(string $text): string
    {
        return "\n  {$this->bold()}{$text}{$this->reset()}\n" . str_repeat('─', 60);
    }

    private function section(string $text): string
    {
        return "  {$this->bold()}{$this->color('INFO')}{$text}{$this->reset()}";
    }

    private function subSection(string $text): string
    {
        return "  {$this->bold()}{$text}:{$this->reset()}";
    }

    private function kv(string $key, string $value): string
    {
        return "  {$this->color('INFO')}{$key}:{$this->reset()} {$value}";
    }

    private function summaryLine(Report $report): string
    {
        $parts = [];

        $critical = $report->criticalCount();
        $warnings = $report->warningCount();
        $oks = $report->okCount();
        $infos = $report->infoCount();

        if ($oks > 0) {
            $parts[] = "{$this->color('OK')}{$oks} OK{$this->reset()}";
        }
        if ($infos > 0) {
            $parts[] = "{$this->color('INFO')}{$infos} Info{$this->reset()}";
        }
        if ($warnings > 0) {
            $parts[] = "{$this->color('WARNING')}{$warnings} Warnings{$this->reset()}";
        }
        if ($critical > 0) {
            $parts[] = "{$this->color('CRITICAL')}{$critical} Critical{$this->reset()}";
        }

        $total = count($report->recommendations);
        $score = $report->score;

        $scoreColor = match (true) {
            $score >= 90 => 'OK',
            $score >= 70 => 'WARNING',
            default => 'CRITICAL',
        };

        return sprintf(
            "  Summary: %s | Score: %s%s/100%s",
            implode(', ', $parts),
            $this->color($scoreColor),
            $score,
            $this->reset(),
        );
    }

    private function color(string $severity): string
    {
        if (!$this->useColors) {
            return '';
        }
        return self::COLORS[$severity] ?? '';
    }

    private function reset(): string
    {
        if (!$this->useColors) {
            return '';
        }
        return self::COLORS['RESET'];
    }

    private function bold(): string
    {
        if (!$this->useColors) {
            return '';
        }
        return self::COLORS['BOLD'];
    }

    private function dim(string $text): string
    {
        if (!$this->useColors) {
            return $text;
        }
        return self::COLORS['DIM'] . $text . self::COLORS['RESET'];
    }

    public static function formatUptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $mins = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days}d";
        }
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($mins > 0) {
            $parts[] = "{$mins}m";
        }
        $parts[] = "{$secs}s";

        return implode(' ', $parts);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.2f GB', $bytes / 1073741824);
        }
        if ($bytes >= 1048576) {
            return sprintf('%.2f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.2f KB', $bytes / 1024);
        }
        return "{$bytes} B";
    }
}
