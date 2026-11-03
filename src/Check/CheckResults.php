<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Check;

use Frootbox\Workbench\Config;
use RuntimeException;

/**
 * The last result of "bin/workbench check" per project, in
 * var/workbench/check.json of the workbench, shown on its start page.
 *
 * @phpstan-type Result array{ok: bool, checkedAt: string, pages: int, pending: int, problems: list<string>}
 */
final readonly class CheckResults
{
    public function __construct(private Config $config) {}

    /**
     * @return array<string, Result> By project
     */
    public function all(): array
    {
        $file = $this->file();
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $results = [];

        foreach (\is_array($data) ? $data : [] as $project => $result) {
            if (\is_array($result)) {
                $results[(string) $project] = self::result($result);
            }
        }

        return $results;
    }

    /**
     * Keeps the results of other projects.
     *
     * @param array<string, Result> $results
     */
    public function save(array $results): void
    {
        $all = [...$this->all(), ...$results];
        ksort($all);
        $directory = \dirname($this->file());

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(\sprintf('Could not create %s.', $directory));
        }

        file_put_contents($this->file(), json_encode($all, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * @param array<mixed> $data
     *
     * @return Result
     */
    public static function result(array $data): array
    {
        $problems = \is_array($data['problems'] ?? null) ? array_values(array_filter($data['problems'], \is_string(...))) : [];

        return [
            'ok' => true === ($data['ok'] ?? null),
            'checkedAt' => \is_string($data['checkedAt'] ?? null) ? $data['checkedAt'] : '',
            'pages' => \is_int($data['pages'] ?? null) ? $data['pages'] : 0,
            'pending' => \is_int($data['pending'] ?? null) ? $data['pending'] : 0,
            'problems' => $problems,
        ];
    }

    private function file(): string
    {
        return $this->config->var('check.json');
    }
}
