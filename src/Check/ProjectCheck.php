<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Check;

use Frootbox\Core\Application;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Url\Addresses;
use Frootbox\Core\Url\UrlPath;
use Frootbox\Core\Url\Urls;
use Frootbox\Workbench\Project\Project;
use Frootbox\Workbench\Project\ProjectMigrations;
use Frootbox\Workbench\Workbench;
use Nyholm\Psr7\ServerRequest;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Checks one project with the current packages before a release (decision
 * 0254): missing packages, blocks:check, open migrations and every
 * published page in every language, requested once as a guest. A status
 * from 500, an exception or a PHP warning is a problem.
 *
 * Runs in a process of its own per project, since two projects may have
 * classes with the same name.
 *
 * @phpstan-import-type Result from CheckResults
 */
final readonly class ProjectCheck
{
    public function __construct(private Workbench $workbench) {}

    /**
     * @return Result
     */
    public function run(Project $project): array
    {
        $problems = [];
        $pages = 0;
        $pending = 0;

        $missing = $this->workbench->source($project)->missing();

        if ([] !== $missing) {
            $problems[] = 'Packages missing: ' . implode(', ', $missing);

            return self::result($problems, $pages, $pending);
        }

        try {
            $application = $this->workbench->application($project);
            $pending = new ProjectMigrations($application, $project->path)->pending();
        } catch (Throwable $error) {
            $problems[] = 'Does not start: ' . $error->getMessage();

            return self::result($problems, $pages, $pending);
        }

        $output = new BufferedOutput();

        if (0 !== $application->runConsole(new ArrayInput(['command' => 'blocks:check']), $output)) {
            $problems[] = 'blocks:check: ' . trim(preg_replace('/\s+/', ' ', $output->fetch()) ?? '');
        }

        if ($pending > 0) {
            $problems[] = \sprintf('%d %s open, pages not checked. Run: bin/workbench migrate %s', $pending, 1 === $pending ? 'migration' : 'migrations', $project->name);

            return self::result($problems, $pages, $pending);
        }

        foreach ($this->urls($application, $project) as $url) {
            ++$pages;
            $problem = $this->request($project, $url);

            if (null !== $problem) {
                $problems[] = $url . ': ' . $problem;
            }
        }

        return self::result($problems, $pages, $pending);
    }

    /**
     * @param list<string> $problems
     *
     * @return Result
     */
    private static function result(array $problems, int $pages, int $pending): array
    {
        return ['ok' => [] === $problems, 'checkedAt' => date('Y-m-d H:i:s'), 'pages' => $pages, 'pending' => $pending, 'problems' => $problems];
    }

    /**
     * Every current address of a published page or object, without the
     * addresses of a fallback.
     *
     * @return list<string> Full URLs
     */
    private function urls(Application $application, Project $project): array
    {
        $container = $application->container();
        $addresses = $container->get(Addresses::class);
        $urls = $container->get(Urls::class);
        $languages = $container->get(Languages::class);

        if (!$addresses instanceof Addresses || !$urls instanceof Urls || !$languages instanceof Languages) {
            return [];
        }

        $list = [];

        foreach ($addresses->fetch(['where' => ['current' => true, 'fallback' => false], 'order' => 'id']) as $address) {
            if (!$languages->has($address->language)) {
                continue;
            }

            $path = $urls->path($address->target, $address->language);
            $host = $languages->get($address->language)->domain ?? $this->workbench->projects->host($project);

            if (null !== $path) {
                $list[] = 'http://' . $host . UrlPath::encode($path);
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * Requests a page in a new installation, as a fresh request would.
     */
    private function request(Project $project, string $url): ?string
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$warnings): bool {
            $warnings[] = \sprintf('%s in %s:%d', $message, $file, $line);

            return true;
        }, \E_WARNING | \E_NOTICE | \E_USER_WARNING | \E_USER_NOTICE);

        try {
            $response = $this->workbench->application($project)->handle(
                new ServerRequest('GET', $url, ['User-Agent' => 'Frootbox Workbench check', 'Accept' => 'text/html']),
            );
            $status = $response->getStatusCode();
        } catch (Throwable $error) {
            return 'Exception: ' . $error->getMessage();
        } finally {
            restore_error_handler();
        }

        if ($status >= 500) {
            return 'Status ' . $status;
        }

        return [] === $warnings ? null : 'PHP: ' . implode('; ', $warnings);
    }
}
