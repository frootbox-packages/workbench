<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Extension;

/**
 * A package as composer.json or installed.json describes it.
 */
final readonly class ComposerPackage
{
    /**
     * @param list<string>                $requires Names of required packages, platform packages included
     * @param array<string, list<string>> $psr4     Namespace prefix => directories, relative to $path
     * @param list<string>                $provides Names it provides or replaces
     * @param string|null                 $version  pretty_version of installed.json or version of composer.json
     */
    public function __construct(
        public string $name,
        public string $path,
        public array $requires,
        public array $psr4,
        public array $provides = [],
        public ?string $version = null,
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, string $path): ?self
    {
        $name = $data['name'] ?? null;

        if (!\is_string($name) || '' === $name) {
            return null;
        }

        $autoload = \is_array($data['autoload'] ?? null) ? $data['autoload'] : [];
        $psr4 = [];

        foreach (\is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [] as $prefix => $directories) {
            $psr4[(string) $prefix] = array_values(array_filter((array) $directories, \is_string(...)));
        }

        return new self(
            strtolower($name),
            $path,
            self::names($data['require'] ?? null),
            $psr4,
            [...self::names($data['provide'] ?? null), ...self::names($data['replace'] ?? null)],
            self::version($data['pretty_version'] ?? $data['version'] ?? null),
        );
    }

    private static function version(mixed $version): ?string
    {
        return \is_string($version) && '' !== $version ? $version : null;
    }

    /**
     * Requirements of PHP itself such as "php" or "ext-intl".
     */
    public static function isPlatform(string $name): bool
    {
        return \in_array($name, ['php', 'php-64bit', 'php-ipv6', 'php-zts', 'php-debug', 'hhvm', 'composer', 'composer-plugin-api', 'composer-runtime-api'], true)
            || str_starts_with($name, 'ext-')
            || str_starts_with($name, 'lib-');
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $map): array
    {
        return \is_array($map) ? array_map(static fn(int|string $name): string => strtolower((string) $name), array_keys($map)) : [];
    }
}
