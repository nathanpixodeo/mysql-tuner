<?php

declare(strict_types=1);

namespace MySQLTuner\Report;

use MySQLTuner\Recommendation\Recommendation;
use RuntimeException;

class JsonFormatter
{
    public function format(Report $report, bool $pretty = true): string
    {
        $data = [
            'version' => $report->version,
            'uptime' => $report->uptime,
            'uptime_human' => ConsoleFormatter::formatUptime($report->uptime),
            'score' => $report->score,
            'summary' => [
                'ok' => $report->okCount(),
                'info' => $report->infoCount(),
                'warnings' => $report->warningCount(),
                'critical' => $report->criticalCount(),
                'total' => count($report->recommendations),
            ],
            // Previously omitted, so the JSON and console renderings disagreed about what the tool
            // had collected.
            'system' => $report->systemMetrics,
            'recommendations' => array_map(
                static fn(Recommendation $r): array => $r->toArray(),
                $report->recommendations,
            ),
        ];

        $flags = ($pretty ? JSON_PRETTY_PRINT : 0)
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            // Server values can contain bytes that are not valid UTF-8 (init_connect and
            // ft_boolean_syntax are common offenders). Without this, json_encode returns false and
            // the declared string return type turns that into a TypeError.
            | JSON_INVALID_UTF8_SUBSTITUTE;

        $json = json_encode($data, $flags);

        if ($json === false) {
            throw new RuntimeException('Failed to encode the report as JSON: ' . json_last_error_msg());
        }

        return $json;
    }
}
