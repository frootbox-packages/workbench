<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The settings of a workbench, from workbench.yaml in its folder and
 * workbench.local.yaml for this computer only (decision 0254):
 *
 *     domain: fb.localhost          # holl24.fb.localhost, holl24.holl.fb.localhost
 *     monorepo: ../frootbox2        # skeleton/ for new projects
 *     roots: [.]                    # searched for <Vendor>/<Site>/
 *     projects:
 *       sandbox: ../frootbox2/sandbox
 *
 * The local file overrides domain and monorepo and adds roots and projects.
 */
final readonly class Config
{
    public const string FILE = 'workbench.yaml';

    public const string LOCAL_FILE = 'workbench.local.yaml';

    public const string DOMAIN = 'fb.localhost';

    /**
     * @param list<string>          $roots    Absolute folders with <Vendor>/<Site>/ inside
     * @param array<string, string> $projects Absolute folder by name
     */
    public function __construct(
        public string $root,
        public string $domain,
        public string $monorepo,
        public array $roots,
        public array $projects,
    ) {}

    public static function load(string $root): self
    {
        $real = realpath($root);

        if (false === $real || !is_file($real . '/' . self::FILE)) {
            throw new RuntimeException(\sprintf('%s has no %s. Create a workbench with: bin/workbench init <folder>', $root, self::FILE));
        }

        $settings = self::read($real . '/' . self::FILE);
        $local = is_file($real . '/' . self::LOCAL_FILE) ? self::read($real . '/' . self::LOCAL_FILE) : [];
        $absolute = static fn(string $path): string => self::absolute($real, $path);

        $domain = strtolower(self::text($local, 'domain') ?? self::text($settings, 'domain') ?? self::DOMAIN);
        $monorepo = $absolute(self::text($local, 'monorepo') ?? self::text($settings, 'monorepo') ?? '../frootbox2');
        $roots = array_map($absolute, [...self::texts($settings, 'roots') ?? ['.'], ...self::texts($local, 'roots') ?? []]);
        $projects = array_map($absolute, [...self::map($settings, 'projects'), ...self::map($local, 'projects')]);

        return new self($real, $domain, $monorepo, array_values(array_unique($roots)), $projects);
    }

    /**
     * The one vendor/ of all projects.
     */
    public function vendor(): string
    {
        return $this->root . '/vendor';
    }

    /**
     * Folder for results such as the last check, may be deleted.
     */
    public function var(string $path = ''): string
    {
        return $this->root . '/var/workbench' . ('' === $path ? '' : '/' . $path);
    }

    /**
     * A path relative to $base, "~" for the home folder, or absolute.
     */
    public static function absolute(string $base, string $path): string
    {
        $home = getenv('HOME');

        if (str_starts_with($path, '~/') && \is_string($home)) {
            $path = $home . substr($path, 1);
        }

        $path = str_starts_with($path, '/') ? $path : $base . '/' . $path;
        $real = realpath($path);

        return false === $real ? rtrim($path, '/') : $real;
    }

    /**
     * @return array<mixed>
     */
    private static function read(string $file): array
    {
        $data = Yaml::parseFile($file);

        if (null === $data) {
            return [];
        }

        if (!\is_array($data)) {
            throw new RuntimeException(\sprintf('%s must hold settings like "domain: fb.localhost".', $file));
        }

        return $data;
    }

    /**
     * @param array<mixed> $settings
     */
    private static function text(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /**
     * @param array<mixed> $settings
     *
     * @return list<string>|null
     */
    private static function texts(array $settings, string $key): ?array
    {
        $value = $settings[$key] ?? null;

        if (!\is_array($value)) {
            return null;
        }

        return array_values(array_filter($value, \is_string(...)));
    }

    /**
     * @param array<mixed> $settings
     *
     * @return array<string, string>
     */
    private static function map(array $settings, string $key): array
    {
        $value = $settings[$key] ?? null;
        $map = [];

        foreach (\is_array($value) ? $value : [] as $name => $path) {
            if (\is_string($path)) {
                $map[(string) $name] = $path;
            }
        }

        return $map;
    }
}
