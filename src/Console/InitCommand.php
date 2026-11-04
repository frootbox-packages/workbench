<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Console;

use Frootbox\Workbench\CentralComposer;
use Frootbox\Workbench\Config;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Makes a folder the workbench: workbench.yaml, composer.json,
 * public/index.php, public/.htaccess and .gitignore. Files that exist are kept, composer.json
 * only gets what is missing.
 */
#[AsCommand(name: 'init', description: 'Makes a folder the workbench, e.g. the folder with all websites')]
final class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('folder', InputArgument::REQUIRED, 'e.g. ~/Sites/frootbox-sites')
            ->addOption('monorepo', null, InputOption::VALUE_REQUIRED, 'The monorepo of Frootbox, by default the one of this workbench')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Websites answer at <site>.<domain>', Config::DOMAIN);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $root = Config::absolute((string) getcwd(), Environment::text($input->getArgument('folder')));
        $monorepoOption = $input->getOption('monorepo');
        $monorepo = \is_string($monorepoOption) ? Config::absolute((string) getcwd(), $monorepoOption) : \dirname(__DIR__, 5);
        $domain = Environment::text($input->getOption('domain'));

        if (!is_dir($root) || !is_file($monorepo . '/skeleton/composer.json')) {
            $io->error(\sprintf('%s must be a folder and %s the monorepo of Frootbox.', $root, $monorepo));

            return Command::FAILURE;
        }

        $relative = CentralComposer::relative($root, $monorepo);
        $written = [];

        if (self::create($root . '/' . Config::FILE, <<<YAML
            # The workbench of Frootbox (decision 0254): every <Vendor>/<Site>/
            # below a root answers at <site>.<domain> and <site>.<vendor>.<domain>
            # with the packages of this folder. workbench.local.yaml (not in Git)
            # can add roots and projects for this computer only.
            domain: {$domain}
            monorepo: {$relative}
            roots:
              - .
            projects:
              sandbox: {$relative}/sandbox

            YAML)) {
            $written[] = Config::FILE;
        }

        if (self::create($root . '/public/index.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            // The host of the workbench in MAMP PRO points here (decision 0254 of Frootbox).
            require dirname(__DIR__) . '/vendor/autoload.php';

            Frootbox\Workbench\Workbench::fromRoot(dirname(__DIR__))->run();

            PHP)) {
            $written[] = 'public/index.php';
        }

        // Apache sends every request that is not a file to index.php, like
        // in a website. Without it /cms and every page answer 404.
        if (self::create($root . '/public/.htaccess', (string) file_get_contents($monorepo . '/skeleton/public/.htaccess'))) {
            $written[] = 'public/.htaccess';
        }

        if (new CentralComposer($root, $monorepo)->write()) {
            $written[] = 'composer.json';
        }

        if (self::ignore($root . '/.gitignore', ['/vendor/', '/var/', '/' . Config::LOCAL_FILE])) {
            $written[] = '.gitignore';
        }

        $io->success([] === $written ? 'The workbench was set up already.' : 'Written: ' . implode(', ', $written));
        $io->text([
            'Next:',
            \sprintf('  cd %s && composer install', $root),
            \sprintf('  In MAMP PRO a host %s with PHP 8.5, without SSL, and the document root', $domain),
            \sprintf('  %s/public', $root),
            \sprintf('  Under Web Server, additional parameters: ServerAlias *.%s', $domain),
            \sprintf('  Then open http://%s (with :8888 while MAMP uses its own ports)', $domain),
        ]);

        return Command::SUCCESS;
    }

    private static function create(string $file, string $content): bool
    {
        if (is_file($file)) {
            return false;
        }

        $directory = \dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(\sprintf('Could not create %s.', $directory));
        }

        file_put_contents($file, $content);

        return true;
    }

    /**
     * Adds lines to .gitignore that it lacks.
     *
     * @param list<string> $lines
     */
    private static function ignore(string $file, array $lines): bool
    {
        $content = is_file($file) ? (string) file_get_contents($file) : '';
        $present = array_map(trim(...), explode("\n", $content));
        $missing = array_values(array_diff($lines, $present));

        if ([] === $missing) {
            return false;
        }

        $prefix = '' === $content || str_ends_with($content, "\n") ? '' : "\n";
        file_put_contents($file, $content . $prefix . "# The workbench (decision 0254 of Frootbox)\n" . implode("\n", $missing) . "\n");

        return true;
    }
}
