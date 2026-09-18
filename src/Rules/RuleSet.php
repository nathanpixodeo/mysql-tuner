<?php

declare(strict_types=1);

namespace MySQLTuner\Rules;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

class RuleSet
{
    /** @var array<string, list<Rule>> */
    private array $rules = [];

    /** @var list<Rule>|null */
    private ?array $flatCache = null;

    public function __construct(
        private readonly string $rulesDir,
    ) {}

    /**
     * Load the rule files that apply to a server version.
     *
     * Idempotent: the rule map is rebuilt from scratch on every call. It used to be appended to, so
     * calling Analyzer::analyze() twice on one instance duplicated every rule and every resulting
     * recommendation, and changed the health score.
     *
     * @throws RuntimeException when no rule file could be read
     */
    public function loadForVersion(string $version): void
    {
        $this->rules = [];
        $this->flatCache = null;

        $files = [
            "mysql-{$this->parseMajorVersion($version)}.json",
            'mysql-default.json',
            'security.json',
        ];

        $seen = [];
        $loaded = 0;

        foreach ($files as $file) {
            $path = "{$this->rulesDir}/{$file}";

            // A version-specific file is optional; the generic ones are not, and that is checked
            // after the loop so a mistyped --rules directory cannot pass as a healthy server.
            if (!is_file($path)) {
                continue;
            }

            foreach ($this->readFile($path) as $group => $groupRules) {
                foreach ($groupRules as $raw) {
                    $key = $raw['key'] ?? null;

                    // First file wins, so a version-specific rule overrides the generic one.
                    if (!is_string($key) || isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $this->rules[$group] ??= [];
                    $this->rules[$group][] = $this->parseRule($path, $raw);
                }
            }

            $loaded++;
        }

        if ($loaded === 0) {
            throw new RuntimeException(
                "No rule files found in '{$this->rulesDir}'. Expected at least mysql-default.json.",
            );
        }
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     * @throws RuntimeException
     */
    private function readFile(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Rule file '{$path}' exists but could not be read.");
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Rule file '{$path}' is not valid JSON: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException("Rule file '{$path}' must contain an object of rule groups.");
        }

        foreach ($data as $group => $groupRules) {
            if (!is_string($group) || !is_array($groupRules)) {
                throw new RuntimeException("Rule file '{$path}' must map group names to lists of rules.");
            }
        }

        /** @var array<string, list<array<string, mixed>>> $data */
        return $data;
    }

    /** @param array<string, mixed> $raw */
    private function parseRule(string $path, array $raw): Rule
    {
        try {
            return Rule::fromArray($raw);
        } catch (InvalidArgumentException $e) {
            // Name the file: "unsupported condition 'lte '" is far easier to act on with it.
            throw new RuntimeException("Invalid rule in '{$path}': {$e->getMessage()}", 0, $e);
        }
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

        return $this->flatCache = $all;
    }

    /** @return list<string> */
    public function getGroups(): array
    {
        return array_keys($this->rules);
    }

    /**
     * Map a metric key to the group that owns it.
     *
     * Built once per load instead of rescanning every group for every matched rule.
     *
     * @return array<string, string>
     */
    public function groupIndex(): array
    {
        $index = [];

        foreach ($this->rules as $group => $groupRules) {
            foreach ($groupRules as $rule) {
                $index[$rule->key] ??= $group;
            }
        }

        return $index;
    }

    private function parseMajorVersion(string $version): string
    {
        // MariaDB reports e.g. "10.11.6-MariaDB", which starts with digits, so this branch also
        // covers it - there is no separate MariaDB rule file to select today.
        if (preg_match('/^(\d+\.\d+)/', $version, $m) === 1) {
            return $m[1];
        }

        return 'default';
    }
}
