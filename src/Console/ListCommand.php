<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Console;

use Closure;
use Frootbox\Workbench\Workbench;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'projects', description: 'Lists all projects with their address and folder', aliases: ['ls'])]
final class ListCommand extends Command
{
    /**
     * @param Closure(): Workbench $workbench
     */
    public function __construct(private readonly Closure $workbench)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workbench = ($this->workbench)();
        $projects = $workbench->projects;
        $rows = [];

        foreach ($projects->all() as $project) {
            $missing = $workbench->source($project)->missing();
            $status = match (true) {
                [] !== $missing => 'packages missing: ' . implode(', ', $missing),
                !$project->installed() => 'not set up',
                default => 'ready',
            };
            $rows[] = ['http://' . $projects->host($project), $project->folder(), $status];
        }

        new SymfonyStyle($input, $output)->table(['Address', 'Folder', 'Status'], $rows);

        return Command::SUCCESS;
    }
}
