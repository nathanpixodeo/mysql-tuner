<?php

namespace MySQLTuner\Collector;

interface CollectorInterface
{
    /** @return array<string, mixed> */
    public function collect(): array;
}
