<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Extension;

/**
 * Loads the classes of the extensions below extensions/ of a project,
 * which the central vendor/ does not know (PSR-4 only).
 */
final class ProjectAutoloader
{
    /** @var array<string, list<string>> Namespace prefix => absolute directories */
    private static array $prefixes = [];

    private static bool $registered = false;

    /**
     * @param array<string, list<string>> $psr4 Namespace prefix => directories, relative to $path
     */
    public static function add(string $path, array $psr4): void
    {
        foreach ($psr4 as $prefix => $directories) {
            $prefix = trim($prefix, '\\') . '\\';

            foreach ($directories as $directory) {
                $absolute = rtrim($path . '/' . trim($directory, '/'), '/');

                if (!\in_array($absolute, self::$prefixes[$prefix] ?? [], true)) {
                    self::$prefixes[$prefix][] = $absolute;
                }
            }
        }

        if (!self::$registered) {
            spl_autoload_register(self::load(...));
            self::$registered = true;
        }
    }

    private static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $directories) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

            foreach ($directories as $directory) {
                if (is_file($directory . '/' . $relative)) {
                    require $directory . '/' . $relative;

                    return;
                }
            }
        }
    }
}
