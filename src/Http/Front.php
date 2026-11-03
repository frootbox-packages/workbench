<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Http;

use Frootbox\Core\Application;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Workbench\Project\Project;
use Frootbox\Workbench\Project\ProjectMigrations;
use Frootbox\Workbench\Workbench;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Decides who answers a request (decision 0254): the start page for the
 * domain itself, a file below public/ of the project, the notice about
 * open migrations, or the installation of the project.
 */
final readonly class Front
{
    public const string MIGRATE = '/_workbench/migrate';

    public function __construct(private Workbench $workbench) {}

    /**
     * A response of the workbench, or the installation that answers.
     */
    public function dispatch(ServerRequestInterface $request): ResponseInterface|Application
    {
        $pages = new Pages($this->workbench);
        $host = $request->getUri()->getHost();
        $projects = $this->workbench->projects;
        $origin = self::origin($request);

        if ($projects->isStart($host)) {
            return $pages->start($origin);
        }

        $project = $projects->byHost($host);

        if (null === $project) {
            return $pages->problem(404, 'No such project', \sprintf(
                'The workbench has no project for <code>%s</code>. See all on <a href="%s">the start page</a>.',
                Pages::e($host),
                Pages::e(\sprintf($origin, $this->workbench->config->domain)),
            ));
        }

        $file = StaticFile::respond($project->path . '/public', $request);

        if (null !== $file) {
            return $file;
        }

        $missing = $this->workbench->source($project)->missing();

        if ([] !== $missing) {
            return $pages->problem(500, 'Packages missing', 'The workbench lacks packages this project requires. Add them with <code>composer require</code> in the folder of the workbench.', $missing);
        }

        try {
            $application = $this->workbench->application($project);
        } catch (ConfigException $error) {
            return $pages->problem(500, 'Not set up', \sprintf(
                '%s Set it up with <code>bin/workbench run %s install</code>.',
                Pages::e($error->getMessage()),
                Pages::e($project->name),
            ));
        }

        return $this->migrations($request, $project, $application, $pages) ?? $application;
    }

    /**
     * The notice or the result of migrating, null without open migrations.
     */
    private function migrations(ServerRequestInterface $request, Project $project, Application $application, Pages $pages): ?ResponseInterface
    {
        $migrations = new ProjectMigrations($application, $project->path);

        try {
            $pending = $migrations->pending();
        } catch (Throwable $error) {
            return $pages->problem(500, 'Database not reachable', Pages::e($error->getMessage()));
        }

        $isMigrate = self::MIGRATE === $request->getUri()->getPath();

        if (0 === $pending) {
            return $isMigrate ? new Response(303, ['Location' => '/']) : null;
        }

        if (!$isMigrate || 'POST' !== $request->getMethod()) {
            return $pages->migrations($project, $pending, $migrations->copies());
        }

        if (!self::sameOrigin($request)) {
            return $pages->problem(403, 'Not allowed', 'Migrations start only from the notice of the workbench itself.');
        }

        $migrations->migrate();

        return new Response(303, ['Location' => '/']);
    }

    /**
     * A form of the page itself, not of another website.
     */
    private static function sameOrigin(ServerRequestInterface $request): bool
    {
        $site = $request->getHeaderLine('Sec-Fetch-Site');

        if ('' !== $site) {
            return 'same-origin' === $site;
        }

        $origin = $request->getHeaderLine('Origin');
        $uri = $request->getUri();
        $port = $uri->getPort();

        return '' !== $origin && $origin === $uri->getScheme() . '://' . $uri->getHost() . (null === $port ? '' : ':' . $port);
    }

    /**
     * The address of another host with scheme and port of this request,
     * e.g. "http://%s:8300".
     */
    private static function origin(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $port = $uri->getPort();
        $scheme = '' === $uri->getScheme() ? 'http' : $uri->getScheme();

        return $scheme . '://%s' . (null === $port ? '' : ':' . $port);
    }
}
