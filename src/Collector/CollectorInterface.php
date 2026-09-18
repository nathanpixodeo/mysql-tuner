<?php

declare(strict_types=1);

namespace MySQLTuner\Collector;

interface CollectorInterface
{
    /** @return array<string, mixed> */
    public function collect(): array;
}
