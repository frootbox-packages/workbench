<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Console;

use Closure;
use Frootbox\Workbench\CentralComposer;
use Frootbox\Workbench\Workbench;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Takes new packages of the monorepo and new shared packages into
 * composer.json of the workbench and runs composer update.
 */
#[AsCommand(name: 'update', description: 'Takes new packages of the monorepo into the workbench and runs composer update')]
final class UpdateCommand extends Command
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
        $config = ($this->workbench)()->config;

        if (new CentralComposer($config->root, $config->monorepo)->write()) {
            $output->writeln('composer.json of the workbench updated.');
        }

        return Composer::run($config->root, ['update', '--no-interaction']);
    }
}
