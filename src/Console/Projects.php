<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Console;

use Frootbox\Workbench\Project\Project;
use Frootbox\Workbench\Workbench;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The projects a command names, or all with --all.
 */
final class Projects
{
    /**
     * @return list<Project>|null Null after an error
     */
    public static function chosen(Workbench $workbench, InputInterface $input, OutputInterface $output, bool $allWithoutNames = false): ?array
    {
        $names = $input->getArgument('projects');
        $names = \is_array($names) ? array_values(array_filter($names, \is_string(...))) : [];
        $all = $input->hasOption('all') && true === $input->getOption('all');

        if ([] === $names && ($all || $allWithoutNames)) {
            return array_values(array_filter($workbench->projects->all(), static fn(Project $project): bool => $project->installed()));
        }

        if ([] === $names) {
            $output->writeln('<error>Name a project, e.g. holl24, or use --all.</error>');

            return null;
        }

        $projects = [];

        foreach ($names as $name) {
            $project = $workbench->projects->get($name);

            if (null === $project) {
                $output->writeln(\sprintf('<error>There is no project %s. See: bin/workbench projects</error>', $name));

                return null;
            }

            $projects[] = $project;
        }

        return $projects;
    }
}
