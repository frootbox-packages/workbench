<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Extension;

use Frootbox\Core\Extension\ExtensionException;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Source\ExtensionSource;

/**
 * The extensions of a project in the workbench (decision 0254): active is
 * what its composer.json requires, directly or through other packages,
 * like live. The packages come from the central vendor/ or from the path
 * repositories of the project (extensions/*), whose classes are loaded at
 * runtime. Versions do not count, the workbench always has the current
 * state.
 */
final class WorkbenchSource implements ExtensionSource
{
    /** @var array{list<ExtensionPackage>, list<string>, array<string, ComposerPackage>}|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly CentralPackages $central,
        private readonly string $projectRoot,
    ) {}

    public function packages(): array
    {
        [$packages, $missing, $local] = $this->resolve();

        if ([] !== $missing) {
            throw new ExtensionException(\sprintf(
                'The workbench lacks packages this project requires: %s. Add them with composer require in the folder of the workbench.',
                implode(', ', $missing),
            ));
        }

        foreach ($local as $package) {
            ProjectAutoloader::add($package->path, $package->psr4);
        }

        return $packages;
    }

    /**
     * Required packages that neither the central vendor/ nor the project has.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return $this->resolve()[1];
    }

    public function fingerprint(): string
    {
        $files = [$this->projectRoot . '/composer.json', ...array_map(static fn(ComposerPackage $package): string => $package->path . '/composer.json', $this->local())];
        $parts = array_map(static fn(string $file): string => is_file($file) ? (string) filemtime($file) : '-', $files);

        return $this->central->fingerprint() . '|' . hash('xxh3', implode(',', $files) . '|' . implode(',', $parts));
    }

    /**
     * @return array{list<ExtensionPackage>, list<string>, array<string, ComposerPackage>}
     */
    private function resolve(): array
    {
        if (null !== $this->resolved) {
            return $this->resolved;
        }

        $project = $this->composerJson();
        $local = $this->local();
        // The workbench is development: require-dev counts, as composer install does without --no-dev.
        $queue = [];

        foreach (['require', 'require-dev'] as $key) {
            if (\is_array($project[$key] ?? null)) {
                $queue = [...$queue, ...array_map(static fn(int|string $name): string => strtolower((string) $name), array_keys($project[$key]))];
            }
        }
        $seen = [];
        $packages = [];
        $missing = [];
        $used = [];

        while ([] !== $queue) {
            $name = array_shift($queue);

            if (isset($seen[$name]) || ComposerPackage::isPlatform($name)) {
                continue;
            }

            $seen[$name] = true;
            $isLocal = isset($local[$name]);
            $package = $local[$name] ?? $this->central->get($name);

            if (null === $package) {
                $missing[] = $name;

                continue;
            }

            if ($package->name !== $name && isset($seen[$package->name])) {
                continue;
            }

            $seen[$package->name] = true;
            $queue = [...$queue, ...$package->requires];

            if ($isLocal) {
                $used[$package->name] = $package;
            }

            if (is_file($package->path . '/' . ExtensionPackage::MANIFEST)) {
                $packages[] = ExtensionPackage::fromManifest($package->name, $package->path, $package->requires, $package->psr4, $isLocal, $package->version);
            }
        }

        sort($missing);

        return $this->resolved = [$packages, $missing, $used];
    }

    /**
     * The packages in the path repositories inside the project, by name.
     *
     * @return array<string, ComposerPackage>
     */
    private function local(): array
    {
        $data = $this->composerJson();
        $repositories = \is_array($data['repositories'] ?? null) ? $data['repositories'] : [];
        $patterns = [];

        foreach ($repositories as $repository) {
            $url = \is_array($repository) && 'path' === ($repository['type'] ?? null) ? ($repository['url'] ?? null) : null;

            // Only folders of the project; the packages of the monorepo come from the central vendor/.
            if (\is_string($url) && !str_starts_with($url, '/') && !str_starts_with($url, '..')) {
                $patterns[] = $this->projectRoot . '/' . trim($url, '/');
            }
        }

        $local = [];

        if ([] === $patterns) {
            $patterns[] = $this->projectRoot . '/extensions/*';
        }

        foreach ($patterns as $pattern) {
            $folders = glob($pattern, \GLOB_ONLYDIR);

            foreach (false === $folders ? [] : $folders as $folder) {
                $package = self::read($folder . '/composer.json', (string) realpath($folder));

                if (null !== $package) {
                    $local[$package->name] = $package;
                }
            }
        }

        return $local;
    }

    /**
     * @return array<mixed>
     */
    private function composerJson(): array
    {
        $file = $this->projectRoot . '/composer.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return \is_array($data) ? $data : [];
    }

    private static function read(string $file, string $path): ?ComposerPackage
    {
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return \is_array($data) ? ComposerPackage::fromArray($data, $path) : null;
    }
}
