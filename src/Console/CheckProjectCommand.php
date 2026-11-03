<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Console;

use Closure;
use Frootbox\Workbench\Check\ProjectCheck;
use Frootbox\Workbench\Workbench;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks one project and prints the result as JSON; "check" starts it in
 * a process of its own per project.
 */
#[AsCommand(name: 'check:project', description: 'Checks one project and prints the result as JSON', hidden: true)]
final class CheckProjectCommand extends Command
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
        $this->addArgument('project', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workbench = ($this->workbench)();
        $project = $workbench->projects->get(Environment::text($input->getArgument('project')));

        if (null === $project) {
            return Command::INVALID;
        }

        $output->writeln(json_encode(new ProjectCheck($workbench)->run($project), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
