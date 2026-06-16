<?php

namespace MySQLTuner\Report;

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
            'recommendations' => array_map(
                fn($r) => $r->toArray(),
                $report->recommendations,
            ),
        ];

        return json_encode(
            $data,
            ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_UNICODE,
        );
    }
}
