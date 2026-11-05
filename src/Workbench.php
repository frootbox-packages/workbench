<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench;

use Frootbox\Core\Application;
use Frootbox\Core\Http\ResponseEmitter;
use Frootbox\Workbench\Extension\CentralPackages;
use Frootbox\Workbench\Extension\WorkbenchSource;
use Frootbox\Workbench\Http\Front;
use Frootbox\Workbench\Project\Project;
use Frootbox\Workbench\Project\Projects;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Runs many local websites with one host and one vendor/ (decisions 0098,
 * 0254). public/index.php of the workbench only calls
 * Workbench::fromRoot(dirname(__DIR__))->run().
 */
final readonly class Workbench
{
    /** Variables that belong to the .env of each project, never to the workbench. */
    public const array PROJECT_VARIABLES = ['APP_ENV', 'APP_SECRET', 'DATABASE_URL', 'MAILER_DSN'];

    public Projects $projects;

    public CentralPackages $central;

    public function __construct(public Config $config)
    {
        $this->projects = new Projects($config);
        $this->central = new CentralPackages($config->vendor());
    }

    public static function fromRoot(string $root): self
    {
        return new self(Config::load($root));
    }

    /**
     * The folder of the workbench: $FROOTBOX_WORKBENCH, else the nearest
     * folder with workbench.yaml from $start upwards.
     */
    public static function locate(string $start): ?string
    {
        $variable = getenv('FROOTBOX_WORKBENCH');

        if (\is_string($variable) && '' !== $variable) {
            return $variable;
        }

        for ($directory = $start; ; $directory = \dirname($directory)) {
            if (is_file($directory . '/' . Config::FILE)) {
                return $directory;
            }

            if (\dirname($directory) === $directory) {
                return null;
            }
        }
    }

    public function source(Project $project): WorkbenchSource
    {
        return new WorkbenchSource($this->central, $project->path);
    }

    /**
     * The installation of a project with the packages of the workbench.
     *
     * @param array<string, string>|null $serverEnvironment Defaults to the real environment (getenv()).
     */
    public function application(Project $project, ?array $serverEnvironment = null): Application
    {
        return Application::fromProjectRoot($project->path, $this->environment($project, $serverEnvironment), [$this->source($project)]);
    }

    /**
     * The environment for a project: real variables win over .env in
     * Frootbox, so those of the project's .env and PROJECT_VARIABLES are
     * left out. Otherwise a DATABASE_URL of the shell would point every
     * project to one database.
     *
     * @param array<string, string>|null $base Defaults to the real environment (getenv())
     *
     * @return array<string, string>
     */
    public function environment(Project $project, ?array $base = null): array
    {
        $environment = $base ?? getenv();
        $file = $project->path . '/.env';
        $own = is_file($file) ? array_keys(new Dotenv()->parse((string) file_get_contents($file), $file)) : [];

        foreach ([...self::PROJECT_VARIABLES, ...$own] as $name) {
            unset($environment[(string) $name]);
        }

        return $environment;
    }

    /**
     * Reads the request from PHP globals and answers it.
     */
    public function run(): void
    {
        LocalTimeZone::apply();
        $factory = new Psr17Factory();
        $request = new ServerRequestCreator($factory, $factory, $factory, $factory)->fromGlobals();
        $answer = new Front($this)->dispatch($request);

        if ($answer instanceof Application) {
            $answer->run();

            return;
        }

        new ResponseEmitter()->emit($answer);
    }
}
