<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Build;

use DateTimeImmutable;
use Frootbox\Core\Config\Config as CoreConfig;
use Frootbox\Workbench\Project\Project;
use Frootbox\Workbench\Workbench;
use RuntimeException;

/**
 * Builds of a project started from its backend (decision 0291): one at a
 * time in the background, with its state in <prefix>run.json and its
 * output in <prefix>run.log next to the builds.
 */
final readonly class BuildRuns
{
    /** Set by the workbench in the environment of each of its websites. */
    public const string ROOT = 'FROOTBOX_WORKBENCH_ROOT';

    public const string PROJECT = 'FROOTBOX_WORKBENCH_PROJECT';

    /** Lines of the output a run shows. */
    private const int TAIL = 40;

    public string $builds;

    /**
     * @param list<string>|null $command Runs instead of the workbench, for tests
     */
    public function __construct(
        public Workbench $workbench,
        public Project $project,
        private ?array $command = null,
    ) {
        $this->builds = $workbench->config->var('builds');
    }

    /**
     * The runs of the website that $config belongs to, null outside the workbench.
     */
    public static function of(CoreConfig $config): ?self
    {
        $root = $config->env(self::ROOT);
        $name = $config->env(self::PROJECT);

        if (null === $root || null === $name || '' === $root || '' === $name) {
            return null;
        }

        $workbench = Workbench::fromRoot($root);
        $project = $workbench->projects->get($name);

        return null === $project ? null : new self($workbench, $project);
    }

    /**
     * Starts a build in the background; with $since also the diff in the
     * fixed folder <prefix>latest.
     *
     * @throws RuntimeException When $since names no build of the project
     */
    public function start(?string $since): BuildRun
    {
        if (null !== $since) {
            // Checked here, so a wrong name does not wait for a whole build.
            Builder::earlier($this->project, $this->builds, $since);
        }

        if (!is_dir($this->builds)) {
            mkdir($this->builds, 0o775, true);
        }

        $arguments = $this->command ?? [$this->php(), $this->workbench->config->root . '/vendor/bin/workbench', 'build', $this->project->name, '--zip'];

        if (null !== $since) {
            $arguments = [...$arguments, '--since', $since, '--latest'];
        }

        // Without nohup: on macOS it refuses to start under a web server
        // without a console. Ignoring SIGHUP and leaving the streams does
        // the same.
        $inner = "trap '' HUP; " . implode(' ', array_map(escapeshellarg(...), $arguments)) . '; echo "[exit $?]"';
        $script = '/bin/sh -c ' . escapeshellarg($inner) . ' > ' . escapeshellarg($this->file('log')) . ' 2>&1 < /dev/null & echo $!';
        $process = proc_open(['/bin/sh', '-c', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $this->workbench->config->root, $this->environment());

        if (!\is_resource($process)) {
            throw new RuntimeException('The build cannot be started.');
        }

        $pid = (int) trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        proc_close($process);

        file_put_contents($this->file('json'), json_encode(['pid' => $pid, 'startedAt' => new DateTimeImmutable()->format(DATE_ATOM), 'since' => $since], \JSON_UNESCAPED_SLASHES) . "\n");

        return $this->show();
    }

    /**
     * The last run with its result, and the build to start from next.
     */
    public function show(): BuildRun
    {
        $latest = basename(Builder::latest($this->project, $this->builds));
        $server = $this->server();
        $data = is_file($this->file('json')) ? json_decode((string) file_get_contents($this->file('json')), true) : null;

        if (!\is_array($data)) {
            return new BuildRun(BuildRun::NONE, $this->builds, $latest, $this->suggestion(), $server);
        }

        $since = \is_string($data['since'] ?? null) ? $data['since'] : null;

        $log = is_file($this->file('log')) ? (string) file_get_contents($this->file('log')) : '';
        $exit = 1 === preg_match('/^\[exit (\d+)\]$/m', $log, $match) ? (int) $match[1] : null;
        $pid = \is_int($data['pid'] ?? null) ? $data['pid'] : 0;
        $running = null === $exit && $pid > 0 && (!\function_exists('posix_kill') || posix_kill($pid, 0));
        $state = match (true) {
            $running => BuildRun::RUNNING,
            0 === $exit => BuildRun::DONE,
            default => BuildRun::FAILED,
        };
        $startedAt = \is_string($data['startedAt'] ?? null) ? new DateTimeImmutable($data['startedAt']) : null;
        $lines = array_values(array_filter(explode("\n", trim($log)), static fn(string $line): bool => '' !== trim($line) && !str_starts_with($line, '[exit ')));

        return new BuildRun(
            $state,
            $this->builds,
            $latest,
            $this->suggestion($since),
            $server,
            $startedAt,
            $since,
            self::folder($log, 'Built:'),
            self::folder($log, 'Diff:'),
            self::count($log, 'Diff:'),
            self::count($log, 'Gone:'),
            $exit,
            \array_slice($lines, -self::TAIL),
        );
    }

    /**
     * Whether a build of the project runs now.
     */
    public function running(): bool
    {
        return BuildRun::RUNNING === $this->show()->state;
    }

    /**
     * Notes that a build is on the server now, after its upload: the next
     * diff starts from it (decision 0292).
     *
     * @throws RuntimeException When it is no build of the project
     */
    public function markUploaded(string $build): BuildRun
    {
        $name = basename(Builder::earlier($this->project, $this->builds, $build));
        file_put_contents($this->file('server'), $name . "\n");

        return $this->show();
    }

    /**
     * The build noted as on the server, if it still exists.
     */
    public function server(): ?string
    {
        $file = $this->file('server');
        $name = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return '' !== $name && is_dir($this->builds . '/' . $name) ? $name : null;
    }

    /**
     * The build to start the next diff from (decision 0292): the one noted
     * as on the server, else the base of the last run, so building again
     * never moves it, else the newest build.
     *
     * @param string|null $since The base of the last run
     */
    public function suggestion(?string $since = null): ?string
    {
        $server = $this->server();

        if (null !== $server) {
            return $server;
        }

        if (null !== $since && is_dir($this->builds . '/' . $since)) {
            return $since;
        }

        $prefix = Builder::prefix($this->project);
        $folders = glob($this->builds . '/' . $prefix . '*', \GLOB_ONLYDIR);
        $names = array_values(array_filter(array_map(basename(...), false === $folders ? [] : $folders), static fn(string $name): bool => 1 === preg_match('/^' . preg_quote($prefix, '/') . '\d{8}-\d{6}$/', $name)));
        sort($names);

        return [] === $names ? null : $names[\count($names) - 1];
    }

    private function file(string $extension): string
    {
        return $this->builds . '/' . Builder::prefix($this->project) . ('server' === $extension ? 'server.txt' : 'run.' . $extension);
    }

    /**
     * The PHP of the command line: a web server runs PHP as a module or FPM.
     */
    private function php(): string
    {
        if (\in_array(\PHP_SAPI, ['cli', 'cli-server'], true)) {
            return \PHP_BINARY;
        }

        return is_executable(\PHP_BINDIR . '/php') ? \PHP_BINDIR . '/php' : \PHP_BINARY;
    }

    /**
     * The environment of the web server lacks what Composer needs, e.g. a
     * PATH with composer and HOME.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $environment = array_diff_key(getenv(), array_flip(Workbench::PROJECT_VARIABLES));
        $path = $environment['PATH'] ?? '';
        $environment['PATH'] = implode(':', array_unique([...array_filter(explode(':', $path), static fn(string $part): bool => '' !== $part), '/usr/local/bin', '/opt/homebrew/bin', '/usr/bin', '/bin']));

        if (!isset($environment['HOME']) && \function_exists('posix_getpwuid') && \function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());

            if (\is_array($user)) {
                $environment['HOME'] = $user['dir'];
            }
        }

        return $environment;
    }

    private static function folder(string $log, string $label): ?string
    {
        return 1 === preg_match('/^' . preg_quote($label, '/') . '\s+(\S+)/m', $log, $match) ? basename($match[1]) : null;
    }

    private static function count(string $log, string $label): ?int
    {
        return 1 === preg_match('/^' . preg_quote($label, '/') . '\s+\S+ \((\d+) files/m', $log, $match) ? (int) $match[1] : null;
    }
}
