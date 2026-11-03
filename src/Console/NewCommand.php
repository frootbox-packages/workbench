<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Console;

use Closure;
use FilesystemIterator;
use Frootbox\Core\Application;
use Frootbox\Workbench\CentralComposer;
use Frootbox\Workbench\Project\Projects;
use Frootbox\Workbench\Workbench;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A new website in the workbench (decision 0254): a copy of skeleton/ with
 * a child theme, SQLite and the superuser of the sandbox, reachable at once.
 * Its composer.json also works on its own with path repositories to the
 * monorepo, like frootbox.de.
 */
#[AsCommand(name: 'new', description: 'Creates a website <Vendor>/<Site> with a child theme and SQLite')]
final class NewCommand extends Command
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
            ->addArgument('name', InputArgument::REQUIRED, 'Folders of vendor and website, e.g. Holl/Holl24')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Name of the website, by default the folder');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workbench = ($this->workbench)();
        $name = Environment::text($input->getArgument('name'));

        if (1 !== preg_match('#^([A-Za-z0-9][A-Za-z0-9_-]*)/([A-Za-z0-9][A-Za-z0-9_-]*)$#', $name, $match)) {
            $io->error('Name the folders of vendor and website, e.g. Holl/Holl24.');

            return Command::INVALID;
        }

        [, $vendor, $site] = $match;
        $root = $workbench->config->roots[0] ?? $workbench->config->root;
        $path = $root . '/' . $vendor . '/' . $site;
        $skeleton = $workbench->config->monorepo . '/skeleton';
        $title = \is_string($input->getOption('title')) ? $input->getOption('title') : $site;
        $slug = Projects::slug($site);
        $theme = $slug . '/theme';

        if (file_exists($path)) {
            $io->error(\sprintf('%s exists already.', $path));

            return Command::FAILURE;
        }

        if (!is_file($skeleton . '/composer.json')) {
            $io->error(\sprintf('%s has no skeleton/. Check "monorepo" in workbench.yaml.', $workbench->config->monorepo));

            return Command::FAILURE;
        }

        self::copy($skeleton, $path);
        $this->composerJson($path, $workbench->config->monorepo, $slug, $title);
        $project = new Workbench($workbench->config)->projects->get($slug . '.' . Projects::slug($vendor));

        if (null === $project) {
            throw new RuntimeException(\sprintf('%s is no project of the workbench, is %s one of its roots?', $path, $root));
        }

        $sources = [$workbench->source($project)];
        $environment = $workbench->environment($project, Environment::withSandboxSettings($workbench));
        $email = Environment::value($environment, 'FROOTBOX_ADMIN_EMAIL') ?? 'admin@example.test';
        $adminName = Environment::value($environment, 'FROOTBOX_ADMIN_NAME') ?? (string) strstr($email, '@', true);
        $steps = [
            ['command' => 'install', '--no-interaction' => true, '--env' => 'dev', '--admin-email' => $email, '--admin-name' => $adminName],
            ['command' => 'make:extension', 'name' => $theme, '--theme' => true, '--title' => $title],
        ];

        foreach ($steps as $step) {
            if (0 !== Application::console($path, new ArrayInput($step), $output, $environment, $sources)) {
                $io->error(\sprintf('"%s" failed in %s.', $step['command'], $path));

                return Command::FAILURE;
            }
        }

        // The theme exists now; require it and make it active.

        $this->activate($path, $theme, $title);
        $io->success([
            \sprintf('%s is ready: http://%s', $title, new Workbench($workbench->config)->projects->host($project)),
            \sprintf('Folder %s, theme %s below extensions/', $path, $theme),
        ]);

        return Command::SUCCESS;
    }

    private function composerJson(string $path, string $monorepo, string $slug, string $title): void
    {
        $file = $path . '/composer.json';
        $data = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new RuntimeException('skeleton/composer.json is invalid.');
        }

        $relative = CentralComposer::relative($path, $monorepo);
        $repositories = array_map(
            static fn(string $folder): array => ['type' => 'path', 'url' => $relative . '/' . $folder, 'options' => ['symlink' => true]],
            ['packages/*', 'packages/modules/*', 'packages/themes/*'],
        );

        $composer = [
            'name' => $slug . '/website',
            'description' => 'The website ' . $title,
            'type' => 'project',
            'license' => 'proprietary',
            'repositories' => [...$repositories, ['type' => 'path', 'url' => 'extensions/*']],
        ];
        $composer = [...$composer, ...array_diff_key($data, $composer)];

        file_put_contents($file, json_encode($composer, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * Requires the theme in composer.json and chooses it in config/app.yaml.
     */
    private function activate(string $path, string $theme, string $title): void
    {
        $file = $path . '/composer.json';
        $data = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new RuntimeException($file . ' is invalid.');
        }

        $require = \is_array($data['require'] ?? null) ? $data['require'] : [];
        $require[$theme] = '*';
        $php = $require['php'] ?? '>=8.5';
        unset($require['php']);
        ksort($require);
        $data['require'] = ['php' => $php, ...$require];
        file_put_contents($file, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");

        $config = $path . '/config/app.yaml';
        $yaml = (string) file_get_contents($config);
        $quoted = "'" . str_replace("'", "''", $title) . "'";

        foreach (['/^  name: .*$/m' => '  name: ' . $quoted, '/^# theme: .*$/m' => 'theme: ' . $theme] as $pattern => $replacement) {
            $yaml = preg_replace($pattern, $replacement, $yaml, 1, $count);

            if (!\is_string($yaml) || 1 !== $count) {
                throw new RuntimeException(\sprintf('skeleton/config/app.yaml has no line for %s.', $pattern));
            }
        }

        file_put_contents($config, $yaml);
    }

    private static function copy(string $from, string $to): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        if (!mkdir($to, 0o777, true) && !is_dir($to)) {
            throw new RuntimeException(\sprintf('Could not create %s.', $to));
        }

        foreach ($items as $item) {
            \assert($item instanceof SplFileInfo);
            $relative = substr($item->getPathname(), \strlen($from) + 1);

            if (1 === preg_match('#^(vendor|\.env)(/|$)#', $relative)) {
                continue;
            }

            $target = $to . '/' . $relative;

            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0o777, true) && !is_dir($target)) {
                    throw new RuntimeException(\sprintf('Could not create %s.', $target));
                }

                continue;
            }

            copy($item->getPathname(), $target);
            chmod($target, (int) fileperms($item->getPathname()) & 0o777);
        }
    }
}
