<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Console;

use Closure;
use Frootbox\Workbench\Check\CheckResults;
use Frootbox\Workbench\Workbench;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks projects with the current packages before a release (decision
 * 0254), each in a process of its own.
 *
 * @phpstan-import-type Result from CheckResults
 */
#[AsCommand(name: 'check', description: 'Checks projects before a release: packages, blocks, migrations and every page')]
final class CheckCommand extends Command
{
    /**
     * @param Closure(): Workbench $workbench
     */
    public function __construct(
        private readonly Closure $workbench,
        private readonly string $binary,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('projects', InputArgument::IS_ARRAY, 'Names of projects, by default all that are set up');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workbench = ($this->workbench)();
        $projects = Projects::chosen($workbench, $input, $output, allWithoutNames: true);

        if (null === $projects) {
            return Command::INVALID;
        }

        $results = [];
        $rows = [];

        foreach ($projects as $project) {
            $io->write(\sprintf('%s … ', $project->name));
            $result = $this->check($workbench, $project->name);
            $results[$project->name] = $result;
            $io->writeln($result['ok'] ? '<info>fine</info>' : '<error>problems</error>');
            $rows[] = [$project->name, $result['pages'], $result['ok'] ? 'fine' : implode("\n", $result['problems'])];
        }

        new CheckResults($workbench->config)->save($results);
        $io->table(['Project', 'Pages', 'Result'], $rows);
        $failed = array_filter($results, static fn(array $result): bool => !$result['ok']);

        if ([] !== $failed) {
            $io->error(\sprintf('%d of %d projects have problems.', \count($failed), \count($results)));

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d projects are fine.', \count($results)));

        return Command::SUCCESS;
    }

    /**
     * @return Result
     */
    private function check(Workbench $workbench, string $project): array
    {
        $command = implode(' ', array_map(escapeshellarg(...), [\PHP_BINARY, $this->binary, 'check:project', $project]));
        $environment = [...getenv(), 'FROOTBOX_WORKBENCH' => $workbench->config->root];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workbench->config->root, $environment);

        if (!\is_resource($process)) {
            return CheckResults::result(['problems' => ['The check did not start.']]);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $data = json_decode(trim($stdout), true);

        if (0 !== $exitCode || !\is_array($data)) {
            return CheckResults::result(['checkedAt' => date('Y-m-d H:i:s'), 'problems' => ['The check stopped: ' . trim($stderr . "\n" . $stdout)]]);
        }

        return CheckResults::result($data);
    }
}
