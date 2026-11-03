<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Console;

use Closure;
use Frootbox\Workbench\Project\Project;
use Frootbox\Workbench\Project\ProjectMigrations;
use Frootbox\Workbench\Workbench;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs the open migrations of projects, an SQLite database copied first.
 */
#[AsCommand(name: 'migrate', description: 'Runs the open migrations of a project or of all, copying SQLite databases first')]
final class MigrateCommand extends Command
{
    /**
     * @param Closure(): Workbench $workbench
     */
    public function __construct(private readonly Closure $workbench)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projects', InputArgument::IS_ARRAY, 'Names of projects, e.g. holl24')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every project that is set up');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workbench = ($this->workbench)();
        $projects = Projects::chosen($workbench, $input, $output);

        if (null === $projects) {
            return Command::INVALID;
        }

        $failed = false;

        foreach ($projects as $project) {
            $failed = !$this->migrate($workbench, $project, $output) || $failed;
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function migrate(Workbench $workbench, Project $project, OutputInterface $output): bool
    {
        try {
            $result = new ProjectMigrations($workbench->application($project), $project->path)->migrate();
        } catch (Throwable $error) {
            $output->writeln(\sprintf('<error>%s: %s</error>', $project->name, $error->getMessage()));

            return false;
        }

        $output->writeln(\sprintf(
            '%s: %d migrations ran%s',
            $project->name,
            \count($result['ran']),
            null === $result['backup'] ? '' : ', copy in ' . $result['backup'],
        ));

        return true;
    }
}
