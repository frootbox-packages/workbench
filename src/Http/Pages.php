<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Http;

use Frootbox\Workbench\Check\CheckResults;
use Frootbox\Workbench\Project\Project;
use Frootbox\Workbench\Workbench;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The own pages of the workbench: the start page with all projects, the
 * notice about open migrations and errors. Plain HTML with its CSS inside,
 * light and dark (decision 0254).
 */
final readonly class Pages
{
    public function __construct(private Workbench $workbench) {}

    /**
     * @param string $origin E.g. "http://" and ":8300" around the host, as "http://%s:8300"
     */
    public function start(string $origin): ResponseInterface
    {
        $results = new CheckResults($this->workbench->config)->all();
        $projects = $this->workbench->projects;
        $rows = '';

        foreach ($projects->all() as $project) {
            $url = \sprintf($origin, $projects->host($project));
            $badges = $this->badges($project, $results[$project->name] ?? null);
            $long = $projects->unique($project) ? \sprintf('<br><span class="muted">also <a href="%s">%s</a></span>', self::e(\sprintf($origin, $project->name . '.' . $this->workbench->config->domain)), self::e($project->name)) : '';
            $rows .= \sprintf(
                '<tr><th scope="row"><a href="%s">%s</a>%s</th><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
                self::e($url),
                self::e($projects->host($project)),
                $long,
                self::e($project->folder()),
                self::e($this->theme($project)),
                $badges,
            );
        }

        $hints = $this->hints();
        $body = '' === $rows
            ? '<p>No projects yet. Create one with <code>bin/workbench new Vendor/Site</code>.</p>'
            : '<table><thead><tr><th scope="col">Address</th><th scope="col">Folder</th><th scope="col">Theme</th><th scope="col">Status</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return self::page(200, 'Workbench', \sprintf(
            '<h1>Workbench</h1><p class="muted">%d projects in <code>%s</code>. Check all with <code>bin/workbench check</code>.</p>%s%s',
            \count($projects->all()),
            self::e($this->workbench->config->root),
            $hints,
            $body,
        ));
    }

    public function migrations(Project $project, int $pending, bool $copies): ResponseInterface
    {
        $copy = $copies
            ? 'A backup of the database goes to <code>storage/backups/</code> first.'
            : 'There is no database to back up, so <strong>no backup</strong> is made.';

        return self::page(503, 'Migrations open · ' . $project->title(), \sprintf(
            '<h1>%s</h1><p>The current core brings <strong>%d %s</strong> this database has not run yet. %s</p>'
            . '<form method="post" action="/_workbench/migrate"><button type="submit">Migrate now</button></form>'
            . '<p class="muted">Or on the console: <code>bin/workbench migrate %s</code></p>',
            self::e($project->title()),
            $pending,
            1 === $pending ? 'migration' : 'migrations',
            $copy,
            self::e($project->name),
        ));
    }

    /**
     * @param list<string> $items
     */
    public function problem(int $status, string $title, string $message, array $items = []): ResponseInterface
    {
        $list = [] === $items ? '' : '<ul>' . implode('', array_map(static fn(string $item): string => '<li><code>' . self::e($item) . '</code></li>', $items)) . '</ul>';

        return self::page($status, $title, \sprintf('<h1>%s</h1><p>%s</p>%s', self::e($title), $message, $list));
    }

    public static function e(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array{ok: bool, checkedAt: string, pages: int, pending: int, problems: list<string>}|null $result
     */
    private function badges(Project $project, ?array $result): string
    {
        $badges = [];

        if (!$project->installed()) {
            $badges[] = self::badge('warning', 'Not set up', 'Run: bin/workbench run ' . $project->name . ' install');
        }

        try {
            $missing = $this->workbench->source($project)->missing();
        } catch (Throwable $error) {
            $missing = [$error->getMessage()];
        }

        if ([] !== $missing) {
            $badges[] = self::badge('error', 'Packages missing', implode(', ', $missing));
        }

        if (!$this->workbench->projects->unique($project)) {
            $badges[] = self::badge('neutral', 'Short name taken', 'Another project is also called ' . $project->short);
        }

        if (null !== $result) {
            $when = substr($result['checkedAt'], 0, 16);
            $badges[] = $result['ok']
                ? self::badge('success', 'Checked', \sprintf('%d pages fine, %s', $result['pages'], $when))
                : self::badge('error', 'Check failed', implode("\n", $result['problems']) . "\n" . $when);
        }

        return implode(' ', $badges);
    }

    private function theme(Project $project): string
    {
        try {
            $config = Yaml::parseFile($project->path . '/config/app.yaml');
        } catch (Throwable) {
            return '';
        }

        $theme = \is_array($config) ? ($config['theme'] ?? null) : null;

        return \is_string($theme) ? $theme : 'frootbox/theme-base';
    }

    /**
     * Notes about the workbench itself.
     */
    private function hints(): string
    {
        $central = $this->workbench->central;
        $updated = $central->updatedAt();

        if (null === $updated) {
            return '<p class="note">The workbench has no <code>vendor/</code> yet. Run <code>composer install</code> in its folder.</p>';
        }

        $files = [
            ...self::glob($this->workbench->config->monorepo . '/packages/*/composer.json'),
            ...self::glob($this->workbench->config->monorepo . '/packages/*/*/composer.json'),
        ];
        $newer = array_filter($files, static fn(string $file): bool => (int) filemtime($file) > $updated);

        return [] === $newer ? '' : '<p class="note">A package of the monorepo changed its dependencies. Run <code>bin/workbench update</code>.</p>';
    }

    /**
     * @return list<string>
     */
    private static function glob(string $pattern): array
    {
        $files = glob($pattern);

        return false === $files ? [] : $files;
    }

    private static function badge(string $tone, string $label, string $detail): string
    {
        return \sprintf('<span class="badge badge--%s" title="%s">%s</span>', $tone, self::e($detail), self::e($label));
    }

    private static function page(int $status, string $title, string $content): ResponseInterface
    {
        $title = self::e($title);
        $html = <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex">
            <title>{$title}</title>
            <style>
            :root { color-scheme: light dark; --bg: #f6f7f9; --surface: #fff; --text: #13202b; --muted: #556270; --line: #dde2e8; --link: #6b21d9; --accent: #7c3aed; --accent-text: #fff;
                --success: #146c43; --success-bg: #e3f4ea; --warning: #8a5300; --warning-bg: #fdf1dc; --error: #b42318; --error-bg: #fde8e7; --neutral-bg: #eceff3; }
            @media (prefers-color-scheme: dark) { :root { --bg: #11161c; --surface: #1a2129; --text: #e8edf2; --muted: #a2adb9; --line: #2e3843; --link: #c4a6ff; --accent: #8b5cf6;
                --success: #8fd9b0; --success-bg: #173426; --warning: #f6c56b; --warning-bg: #3a2a0f; --error: #ffaaa3; --error-bg: #3d1714; --neutral-bg: #26303a; } }
            * { box-sizing: border-box; }
            body { margin: 0; padding: 2rem 1rem; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, sans-serif; }
            main { max-inline-size: 72rem; margin-inline: auto; }
            h1 { margin: 0 0 .25rem; font-size: 1.75rem; }
            a { color: var(--link); }
            code { font: .875em ui-monospace, monospace; }
            .muted { color: var(--muted); }
            .note { padding: .75rem 1rem; border-radius: .5rem; background: var(--warning-bg); color: var(--warning); }
            table { inline-size: 100%; border-collapse: collapse; background: var(--surface); border: 1px solid var(--line); border-radius: .5rem; overflow: hidden; }
            th, td { padding: .6rem .8rem; border-block-end: 1px solid var(--line); text-align: start; vertical-align: top; }
            thead th { font-size: .8rem; color: var(--muted); }
            tbody th { font-weight: 600; }
            .badge { display: inline-block; margin: 0 .25rem .25rem 0; padding: .1rem .5rem; border-radius: 1rem; font-size: .8rem; font-weight: 600; background: var(--neutral-bg); }
            .badge--success { background: var(--success-bg); color: var(--success); }
            .badge--warning { background: var(--warning-bg); color: var(--warning); }
            .badge--error { background: var(--error-bg); color: var(--error); }
            button { padding: .6rem 1.1rem; border: 0; border-radius: .5rem; background: var(--accent); color: var(--accent-text); font: inherit; font-weight: 600; cursor: pointer; }
            button:focus-visible, a:focus-visible { outline: 3px solid var(--link); outline-offset: 2px; }
            @media (max-width: 40rem) { table, thead, tbody, tr, th, td { display: block; } thead { display: none; } tr { border-block-end: 1px solid var(--line); } th, td { border: 0; padding-block: .25rem; } }
            </style>
            </head>
            <body><main>{$content}</main></body>
            </html>
            HTML;

        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'], $html);
    }
}
