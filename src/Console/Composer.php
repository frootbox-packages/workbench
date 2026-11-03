<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Console;

use RuntimeException;

/**
 * Runs Composer with the PHP of the workbench: $COMPOSER_BINARY, else
 * "composer" from the PATH.
 */
final class Composer
{
    /**
     * @param list<string> $arguments
     */
    public static function run(string $directory, array $arguments): int
    {
        $binary = getenv('COMPOSER_BINARY');

        if (!\is_string($binary) || '' === $binary) {
            $found = shell_exec('command -v composer 2>/dev/null');
            $binary = \is_string($found) ? trim($found) : '';
        }

        if ('' === $binary) {
            throw new RuntimeException('Composer was not found. Set COMPOSER_BINARY to its path.');
        }

        $command = implode(' ', array_map(escapeshellarg(...), [\PHP_BINARY, $binary, ...$arguments, '--working-dir=' . $directory]));
        passthru($command, $exitCode);

        return $exitCode;
    }
}
