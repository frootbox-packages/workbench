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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The workbench without MAMP: the web server of PHP, one request at a time.
 */
#[AsCommand(name: 'serve', description: 'Runs the workbench with the web server of PHP, without MAMP')]
final class ServeCommand extends Command
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
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port on 127.0.0.1', '8300');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = ($this->workbench)()->config;
        $port = (int) Environment::text($input->getOption('port'));
        $output->writeln(\sprintf('Workbench at http://%s:%d (Ctrl+C stops it)', $config->domain, $port));
        passthru(implode(' ', array_map(escapeshellarg(...), [\PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $config->root . '/public', $config->root . '/public/index.php'])), $exitCode);

        return $exitCode;
    }
}
