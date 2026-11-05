<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Console;

use Frootbox\Core\Application;
use Frootbox\Workbench\LocalTimeZone;
use Frootbox\Workbench\Workbench;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * bin/workbench (decision 0254).
 */
final class WorkbenchConsole
{
    /**
     * @param list<string> $argv
     */
    public static function run(array $argv, string $binary): int
    {
        LocalTimeZone::apply();
        $output = new ConsoleOutput();
        $root = Workbench::locate((string) getcwd());
        $workbench = static function () use ($root): Workbench {
            if (null === $root) {
                throw new RuntimeException('No workbench here: no workbench.yaml in this folder or above, and FROOTBOX_WORKBENCH is not set. Create one with: bin/workbench init <folder>');
            }

            return Workbench::fromRoot($root);
        };

        // "run" hands everything after the project to bin/frootbox of the
        // project, options included.
        if ('run' === ($argv[1] ?? null) && isset($argv[2]) && !str_starts_with($argv[2], '-')) {
            try {
                $bench = $workbench();
            } catch (RuntimeException $error) {
                $output->getErrorOutput()->writeln('<error>' . $error->getMessage() . '</error>');

                return 1;
            }

            $project = $bench->projects->get($argv[2]);

            if (null === $project) {
                $output->getErrorOutput()->writeln(\sprintf('<error>There is no project %s. See: bin/workbench projects</error>', $argv[2]));

                return 1;
            }

            return Application::console($project->path, new ArgvInput(['frootbox', ...\array_slice($argv, 3)]), $output, $bench->environment($project, Environment::withSandboxSettings($bench)), [$bench->source($project)]);
        }

        $console = new ConsoleApplication('Frootbox Workbench');
        $console->setAutoExit(false);
        $console->addCommands([
            new InitCommand(),
            new UpdateCommand($workbench),
            new ListCommand($workbench),
            new NewCommand($workbench),
            new RunCommand(),
            new MigrateCommand($workbench),
            new CheckCommand($workbench, $binary),
            new CheckProjectCommand($workbench),
            new ServeCommand($workbench),
            new BuildCommand($workbench),
        ]);

        return $console->run(new ArgvInput($argv), $output);
    }
}
