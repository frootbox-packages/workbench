<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Temporary folders for the tests of the workbench.
 */
final class Folder
{
    public static function create(): string
    {
        $folder = sys_get_temp_dir() . '/frootbox-workbench-' . bin2hex(random_bytes(6));
        mkdir($folder, 0o777, true);

        return (string) realpath($folder);
    }

    /**
     * Writes a file, creating its folders.
     */
    public static function write(string $file, string $content): void
    {
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0o777, true);
        }

        file_put_contents($file, $content);
    }

    /**
     * A minimal project: composer.json with the requirements and config/app.yaml.
     *
     * @param array<string, mixed> $composer
     */
    public static function project(string $path, array $composer = []): void
    {
        self::write($path . '/composer.json', json_encode(['name' => 'test/' . basename($path), ...$composer], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        self::write($path . '/config/app.yaml', "app:\n  name: Test\n");
    }

    public static function remove(string $folder): void
    {
        if (!is_dir($folder)) {
            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            \assert($item instanceof SplFileInfo);
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($folder);
    }
}
