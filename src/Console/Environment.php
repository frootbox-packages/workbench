<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Console;

use Frootbox\Workbench\Workbench;
use Symfony\Component\Dotenv\Dotenv;

/**
 * The environment for commands of a project: the shell, plus the
 * superuser from sandbox.env of the monorepo (FROOTBOX_ADMIN_*), so
 * "install" and "new" create the same superuser as the sandbox. The shell
 * wins.
 */
final class Environment
{
    /**
     * @return array<string, string>
     */
    public static function withSandboxSettings(Workbench $workbench): array
    {
        $environment = getenv();
        $file = $workbench->config->monorepo . '/sandbox.env';

        if (!is_file($file)) {
            return $environment;
        }

        foreach (new Dotenv()->parse((string) file_get_contents($file), $file) as $name => $value) {
            $name = (string) $name;

            if (\is_string($value) && str_starts_with($name, 'FROOTBOX_ADMIN_') && '' === trim($environment[$name] ?? '')) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    /**
     * An argument or option of a command as text.
     */
    public static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /**
     * A variable of the environment for commands, null if empty.
     *
     * @param array<string, string> $environment
     */
    public static function value(array $environment, string $name): ?string
    {
        $value = trim($environment[$name] ?? '');

        return '' === $value ? null : $value;
    }
}
