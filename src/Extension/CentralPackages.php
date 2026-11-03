<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Extension;

use RuntimeException;

/**
 * The packages in the one vendor/ of the workbench, read from
 * vendor/composer/installed.json.
 */
final class CentralPackages
{
    /** @var array<string, ComposerPackage>|null By name, provided names included */
    private ?array $packages = null;

    public function __construct(public readonly string $vendor) {}

    public function get(string $name): ?ComposerPackage
    {
        return $this->all()[strtolower($name)] ?? null;
    }

    /**
     * Changes whenever composer install or update ran.
     */
    public function fingerprint(): string
    {
        $file = $this->file();

        return is_file($file) ? (string) filemtime($file) . '-' . (string) filesize($file) : 'none';
    }

    public function installed(): bool
    {
        return is_file($this->file());
    }

    /**
     * When composer install or update last ran, null if never.
     */
    public function updatedAt(): ?int
    {
        $time = is_file($this->file()) ? filemtime($this->file()) : false;

        return false === $time ? null : $time;
    }

    /**
     * @return array<string, ComposerPackage>
     */
    private function all(): array
    {
        if (null !== $this->packages) {
            return $this->packages;
        }

        $file = $this->file();
        $packages = [];

        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            $list = \is_array($data) ? ($data['packages'] ?? $data) : null;

            if (!\is_array($list)) {
                throw new RuntimeException(\sprintf('%s cannot be read. Run composer install in the workbench.', $file));
            }

            foreach ($list as $entry) {
                $installPath = \is_array($entry) ? ($entry['install-path'] ?? null) : null;

                if (!\is_array($entry) || !\is_string($installPath)) {
                    continue;
                }

                // Composer keeps the path relative to vendor/composer/.
                $path = realpath(str_starts_with($installPath, '/') ? $installPath : $this->vendor . '/composer/' . $installPath);
                $package = false === $path ? null : ComposerPackage::fromArray($entry, $path);

                if (null === $package) {
                    continue;
                }

                foreach ($package->provides as $provided) {
                    $packages[$provided] ??= $package;
                }

                $packages[$package->name] = $package;
            }
        }

        return $this->packages = $packages;
    }

    private function file(): string
    {
        return $this->vendor . '/composer/installed.json';
    }
}
