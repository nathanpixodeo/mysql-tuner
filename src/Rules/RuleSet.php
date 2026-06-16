<?php

namespace MySQLTuner\Rules;

class RuleSet
{
    /** @var array<string, list<Rule>> */
    private array $rules = [];

    /** @var list<Rule> flat cache */
    private ?array $flatCache = null;

    public function __construct(
        private readonly string $rulesDir,
    ) {}

    public function loadForVersion(string $version): void
    {
        $major = $this->parseMajorVersion($version);

        $files = [
            "mysql-{$major}.json",
            'mysql-default.json',
            'security.json',
        ];

        $seen = [];

        foreach ($files as $file) {
            $path = "{$this->rulesDir}/{$file}";
            if (!file_exists($path)) {
                continue;
            }

            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            foreach ($data as $group => $groupRules) {
                $this->rules[$group] ??= [];
                foreach ($groupRules as $raw) {
                    $key = $raw['key'];
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $this->rules[$group][] = Rule::fromArray($raw);
                }
            }
        }

        $this->flatCache = null;
    }

    /** @return list<Rule> */
    public function getRules(?string $group = null): array
    {
        if ($group !== null) {
            return $this->rules[$group] ?? [];
        }

        if ($this->flatCache !== null) {
            return $this->flatCache;
        }

        $all = [];
        foreach ($this->rules as $groupRules) {
            array_push($all, ...$groupRules);
        }
        $this->flatCache = $all;
        return $all;
    }

    /** @return list<string> */
    public function getGroups(): array
    {
        return array_keys($this->rules);
    }

    private function parseMajorVersion(string $version): string
    {
        if (preg_match('/^(\d+\.\d+)/', $version, $m)) {
            return $m[1];
        }
        if (stripos($version, 'MariaDB') !== false) {
            return 'mariadb';
        }
        return 'default';
    }
}
