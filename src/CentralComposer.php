<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench;

use RuntimeException;

/**
 * The composer.json of the workbench (decision 0254): path repositories to
 * the packages of the monorepo and to the shared packages of each vendor
 * (<Vendor>/packages/*), all packages of the monorepo required. Packages
 * someone added by hand, such as a library a website needs, stay.
 */
final readonly class CentralComposer
{
    /** Folders of the monorepo with packages. */
    public const array FOLDERS = ['packages/*', 'packages/modules/*', 'packages/themes/*', 'packages/tools/*'];

    public function __construct(
        private string $root,
        private string $monorepo,
    ) {}

    /**
     * Writes composer.json and tells whether it changed.
     */
    public function write(): bool
    {
        $file = $this->root . '/composer.json';
        $before = is_file($file) ? (string) file_get_contents($file) : '';
        $existing = '' === $before ? [] : json_decode($before, true);

        if (!\is_array($existing)) {
            throw new RuntimeException(\sprintf('%s is no valid JSON.', $file));
        }

        $after = json_encode($this->build($existing), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";

        if ($after === $before) {
            return false;
        }

        file_put_contents($file, $after);

        return true;
    }

    /**
     * @param array<mixed> $existing
     *
     * @return array<mixed>
     */
    public function build(array $existing): array
    {
        $monorepo = self::relative($this->root, $this->monorepo);
        $repositories = [];

        foreach (self::FOLDERS as $folder) {
            $repositories[] = ['type' => 'path', 'url' => $monorepo . '/' . $folder, 'options' => ['symlink' => true]];
        }

        // Composer stops at a path repository without any package.
        if ([] !== self::glob($this->root . '/*/packages/*/composer.json')) {
            $repositories[] = ['type' => 'path', 'url' => '*/packages/*', 'options' => ['symlink' => true]];
        }

        $ours = array_column($repositories, 'url');

        foreach (\is_array($existing['repositories'] ?? null) ? $existing['repositories'] : [] as $repository) {
            $url = \is_array($repository) ? ($repository['url'] ?? null) : null;

            if (!\in_array($url, $ours, true) && !(\is_string($url) && str_ends_with($url, '/packages/*'))) {
                $repositories[] = $repository;
            }
        }

        $require = \is_array($existing['require'] ?? null) ? $existing['require'] : [];
        $require['php'] ??= '>=8.5';

        foreach ($this->packages() as $name) {
            $require[$name] ??= '@dev';
        }

        ksort($require);
        $php = $require['php'];
        unset($require['php']);

        return [
            'name' => 'local/frootbox-workbench',
            'description' => 'The workbench: runs every website in this folder with one vendor/ (decision 0254 of Frootbox)',
            'type' => 'project',
            'license' => 'proprietary',
            ...$existing,
            'repositories' => $repositories,
            'require' => ['php' => $php, ...$require],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'config' => [...(\is_array($existing['config'] ?? null) ? $existing['config'] : []), 'sort-packages' => true],
        ];
    }

    /**
     * The path from one folder to another, e.g. "../frootbox2".
     */
    public static function relative(string $from, string $to): string
    {
        $from = explode('/', trim($from, '/'));
        $to = explode('/', trim($to, '/'));

        while ([] !== $from && [] !== $to && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        $path = implode('/', [...array_fill(0, \count($from), '..'), ...$to]);

        return '' === $path ? '.' : $path;
    }

    /**
     * Names of all packages in the monorepo.
     *
     * @return list<string>
     */
    private function packages(): array
    {
        $names = [];

        foreach (self::FOLDERS as $folder) {
            foreach (self::glob($this->monorepo . '/' . $folder . '/composer.json') as $file) {
                $data = json_decode((string) file_get_contents($file), true);
                $name = \is_array($data) ? ($data['name'] ?? null) : null;

                if (\is_string($name)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function glob(string $pattern): array
    {
        $files = glob($pattern);

        return false === $files ? [] : $files;
    }
}
