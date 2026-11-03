<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Project;

use Frootbox\Workbench\Config;

/**
 * All projects of a workbench and their addresses (decision 0254): every
 * <Vendor>/<Site>/ below a root plus the projects named in
 * workbench.yaml. "Holl/Holl24" answers to holl24.holl.<domain> and, while
 * no other project is called holl24, to holl24.<domain>.
 */
final class Projects
{
    /** @var array<string, Project>|null By name */
    private ?array $all = null;

    public function __construct(private readonly Config $config) {}

    /**
     * "Holl24" becomes "holl24", "Frootbox DE" "frootbox-de".
     */
    public static function slug(string $name): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name));

        return trim($slug, '-');
    }

    public static function isProject(string $folder): bool
    {
        return is_file($folder . '/composer.json') && is_file($folder . '/config/app.yaml');
    }

    /**
     * @return array<string, Project> By name, sorted
     */
    public function all(): array
    {
        if (null !== $this->all) {
            return $this->all;
        }

        $all = [];

        foreach ($this->config->projects as $name => $path) {
            $slug = self::slug($name);

            if ('' !== $slug && self::isProject($path)) {
                $all[$slug] = new Project($slug, $slug, $path, null, $name);
            }
        }

        foreach ($this->config->roots as $root) {
            foreach (self::folders($root) as $vendor) {
                foreach (self::folders($root . '/' . $vendor) as $site) {
                    $path = $root . '/' . $vendor . '/' . $site;
                    $short = self::slug($site);
                    $name = $short . '.' . self::slug($vendor);

                    $named = array_any($all, static fn(Project $project): bool => $project->path === realpath($path));

                    if ('' !== $short && !isset($all[$name]) && !$named && self::isProject($path)) {
                        $all[$name] = new Project($name, $short, $path, $vendor, $site);
                    }
                }
            }
        }

        ksort($all);

        return $this->all = $all;
    }

    public function get(string $name): ?Project
    {
        $name = strtolower($name);

        return $this->all()[$name] ?? $this->byShortName($name);
    }

    /**
     * Whether the short name belongs to this project alone.
     */
    public function unique(Project $project): bool
    {
        return $this->byShortName($project->short)?->name === $project->name;
    }

    /**
     * The host to use in links, e.g. "holl24.fb.localhost".
     */
    public function host(Project $project): string
    {
        return ($this->unique($project) ? $project->short : $project->name) . '.' . $this->config->domain;
    }

    /**
     * The project of a host, or null for the start page (the domain
     * itself) and unknown hosts.
     */
    public function byHost(string $host): ?Project
    {
        $label = $this->label($host);

        return null === $label || '' === $label ? null : $this->get($label);
    }

    /**
     * Whether a host is the start page of the workbench.
     */
    public function isStart(string $host): bool
    {
        return '' === $this->label($host);
    }

    /**
     * The part before the domain: "" for the domain itself, null for
     * another host.
     */
    private function label(string $host): ?string
    {
        $host = rtrim(strtolower(preg_replace('/:\d+$/', '', $host) ?? $host), '.');
        $domain = $this->config->domain;

        if ($host === $domain) {
            return '';
        }

        return str_ends_with($host, '.' . $domain) ? substr($host, 0, -\strlen('.' . $domain)) : null;
    }

    private function byShortName(string $short): ?Project
    {
        $found = array_values(array_filter($this->all(), static fn(Project $project): bool => $project->short === $short));

        return 1 === \count($found) ? $found[0] : null;
    }

    /**
     * @return list<string>
     */
    private static function folders(string $directory): array
    {
        $entries = is_dir($directory) ? scandir($directory) : false;
        $folders = [];

        foreach (false === $entries ? [] : $entries as $entry) {
            if (!str_starts_with($entry, '.') && !\in_array($entry, ['vendor', 'var', 'public', 'node_modules', 'packages'], true) && is_dir($directory . '/' . $entry)) {
                $folders[] = $entry;
            }
        }

        return $folders;
    }
}
