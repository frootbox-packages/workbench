<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Console;

use Closure;
use Frootbox\Workbench\Build\Builder;
use Frootbox\Workbench\Workbench;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds a website for a webspace (decision 0267).
 */
#[AsCommand(name: 'build', description: 'Builds a website as a folder to upload, with its own vendor/ and no symlinks')]
final class BuildCommand extends Command
{
    /** Set by the workbench for its projects; a build sets its own. */
    private const array CLEARED = ['APP_ENV', 'APP_SECRET', 'DATABASE_URL', 'MAILER_DSN'];

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
            ->addArgument('project', InputArgument::REQUIRED, 'Name of the project, e.g. holl24')
            ->addOption('with-content', null, InputOption::VALUE_NONE, 'Also the database and the media of storage/ and public/media/, for the first upload')
            ->addOption('zip', null, InputOption::VALUE_NONE, 'Also a ZIP file next to the folder');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workbench = ($this->workbench)();
        $name = Environment::text($input->getArgument('project'));
        $project = $workbench->projects->get($name);

        if (null === $project) {
            $output->writeln(\sprintf('<error>There is no project %s. See: bin/workbench projects</error>', $name));

            return Command::INVALID;
        }

        $builder = new Builder(
            Composer::run(...),
            self::console(...),
            static function (string $line) use ($output): void {
                $output->writeln('<info>' . $line . '</info>');
            },
        );

        try {
            $build = $builder->build($project, $workbench->config->var('builds'), true === $input->getOption('with-content'), true === $input->getOption('zip'));
        } catch (RuntimeException $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('Built: ' . $build['folder']);

        if (null !== $build['zip']) {
            $size = filesize($build['zip']);
            $output->writeln(\sprintf('ZIP:   %s (%.1f MB)', $build['zip'], (false === $size ? 0 : $size) / 1048576));
        }

        $output->writeln('Next:  DEPLOY.md in the folder says how to upload it.');

        foreach ($build['warnings'] as $warning) {
            $output->writeln('<comment>Warning: ' . $warning . '</comment>');
        }

        return Command::SUCCESS;
    }

    /**
     * Runs bin/frootbox of a build, with the environment of the shell
     * without the one of the workbench.
     *
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     */
    private static function console(string $folder, array $arguments, array $environment): int
    {
        $base = array_diff_key(getenv(), array_flip(self::CLEARED));
        $process = proc_open([\PHP_BINARY, $folder . '/bin/frootbox', ...$arguments, '--no-interaction'], [1 => \STDOUT, 2 => \STDERR], $pipes, $folder, [...$base, ...$environment]);

        if (!\is_resource($process)) {
            throw new RuntimeException('bin/frootbox of the build cannot be started.');
        }

        return proc_close($process);
    }
}
