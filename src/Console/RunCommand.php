<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Only for the help: WorkbenchConsole hands "run <project> …" to
 * bin/frootbox of the project before the console sees it.
 */
#[AsCommand(name: 'run', description: 'Runs a command of bin/frootbox in a project, e.g. run holl24 seed seed/site.yaml')]
final class RunCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::REQUIRED, 'Name of the project, e.g. holl24 or holl24.holl')
            ->addArgument('command-line', InputArgument::IS_ARRAY, 'The command of bin/frootbox with its arguments and options');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>Put the project right after "run", e.g. bin/workbench run holl24 cache:clear</error>');

        return Command::INVALID;
    }
}
