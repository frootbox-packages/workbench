<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Build;

use Closure;
use DateTimeImmutable;
use FilesystemIterator;
use Frootbox\Core\Install\Installer;
use Frootbox\Workbench\Project\Project;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

/**
 * Builds a website of the workbench for a webspace (decision 0267): a
 * folder with its own vendor/, the packages copied instead of linked
 * (Composer leaves out what their .gitattributes mark export-ignore), the
 * files of the extensions published, ready to upload by FTP.
 */
final readonly class Builder
{
    /** Never part of a build, relative to the project */
    public const array LEFT_OUT = ['vendor', 'var', 'migration', '.env', 'public/assets', 'composer.lock', 'storage/backups'];

    /** Left out at any depth */
    public const array LEFT_OUT_ANYWHERE = ['.git', 'node_modules', '.DS_Store'];

    /**
     * In the folder of the website, for a document root set to it instead
     * of public/: nothing is served then (decision 0270). Like skeleton/.htaccess.
     */
    public const string GUARD = <<<'HTACCESS'
        # The web server shows public/, it is the document root. This file only
        # counts when it shows this folder instead: then nothing here is served,
        # neither .env nor the database in storage/ nor the code (decision 0270).
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        <IfModule !mod_authz_core.c>
            Order deny,allow
            Deny from all
        </IfModule>

        HTACCESS;

    /** The content of a website, only with --with-content */
    public const array CONTENT = ['storage', 'public/media'];

    /**
     * @param Closure(string, list<string>): int                       $composer Runs Composer in a folder, gives the exit code
     * @param Closure(string, list<string>, array<string, string>): int $console  Runs bin/frootbox in a folder with an environment
     * @param Closure(string): void                                     $say      Writes a line for the developer
     */
    public function __construct(
        private Closure $composer,
        private Closure $console,
        private Closure $say,
    ) {}

    /**
     * @return array{folder: string, zip: string|null, warnings: list<string>}
     */
    public function build(Project $project, string $builds, bool $withContent = false, bool $zip = false, ?DateTimeImmutable $now = null): array
    {
        $name = self::prefix($project) . ($now ?? new DateTimeImmutable())->format('Ymd-His');
        $folder = $builds . '/' . $name;

        if (file_exists($folder)) {
            throw new RuntimeException(\sprintf('%s exists already.', $folder));
        }

        ($this->say)(\sprintf('Copying %s to %s', $project->title(), $folder));
        self::copy($project->path, $folder, $withContent);
        $database = $folder . '/storage/database.sqlite';
        $hasDatabase = is_file($database);

        // A project of its own may have one already, e.g. from skeleton/.
        if (!is_file($folder . '/.htaccess')) {
            file_put_contents($folder . '/.htaccess', self::GUARD);
        }

        foreach (['storage', 'var'] as $empty) {
            if (!is_dir($folder . '/' . $empty)) {
                mkdir($folder . '/' . $empty, 0o775, true);
            }
        }

        $original = (string) file_get_contents($project->path . '/composer.json');
        $composer = json_decode($original, true);

        if (!\is_array($composer)) {
            throw new RuntimeException(\sprintf('%s/composer.json cannot be read.', $project->path));
        }

        file_put_contents($folder . '/composer.json', json_encode(self::composerForBuild($composer, $project->path), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
        ($this->say)('Installing the packages, copied instead of linked, without require-dev');

        if (0 !== ($this->composer)($folder, ['update', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress'])) {
            throw new RuntimeException('Composer failed, see above. The unfinished build stays in ' . $folder);
        }

        file_put_contents($folder . '/composer.json', $original);

        $environment = self::environment($project->path);
        $live = ['APP_ENV' => 'prod', 'DATABASE_URL' => Installer::DEFAULT_DATABASE_URL, ...(null === $environment['APP_SECRET'] ? [] : ['APP_SECRET' => $environment['APP_SECRET']])];

        ($this->say)('Publishing the files of the extensions');

        if (0 !== ($this->console)($folder, ['assets:publish'], $live)) {
            throw new RuntimeException('assets:publish failed, see above. The unfinished build stays in ' . $folder);
        }

        // The connection to the hub belongs to this installation, not to a copy (decision 0284).
        if ($withContent && $hasDatabase) {
            ($this->say)('Leaving out the connection to the hub');

            if (0 !== ($this->console)($folder, ['hub:disconnect', '--local', '--forget-key'], $live)) {
                throw new RuntimeException('hub:disconnect failed, see above. The unfinished build stays in ' . $folder);
            }
        }

        // The packages of the project run from their copy in vendor/.
        self::remove($folder . '/extensions');

        ($this->say)('Starting the build once as it runs live');

        if (0 !== ($this->console)($folder, ['about'], $live)) {
            throw new RuntimeException('The build does not start, see above. It stays in ' . $folder);
        }

        // The console opens SQLite and leaves an empty database behind. Uploaded
        // over a running website, it would replace the one there. The caches
        // belong to this machine.
        if (!$hasDatabase) {
            self::remove($database);
        }

        self::remove($folder . '/var');
        mkdir($folder . '/var', 0o775, true);

        file_put_contents($folder . '/.env.live', self::envLive($environment['APP_SECRET']));
        file_put_contents($folder . '/DEPLOY.md', self::deployGuide($project, $withContent));
        // On the server it names the build that runs there, for --since.
        file_put_contents($folder . '/BUILD', $name . "\n");

        return ['folder' => $folder, 'zip' => $zip ? self::zip($folder, $folder . '.zip') : null, 'warnings' => self::warnings($folder)];
    }

    /**
     * composer.json of the project for the build: path repositories outside
     * the project point to their absolute folder, every one copies instead of
     * linking, require-dev and its autoloading are left out.
     *
     * @param array<mixed> $composer
     *
     * @return array<mixed>
     */
    public static function composerForBuild(array $composer, string $projectPath): array
    {
        $repositories = [];

        foreach (\is_array($composer['repositories'] ?? null) ? $composer['repositories'] : [] as $key => $repository) {
            if (\is_array($repository) && 'path' === ($repository['type'] ?? null) && \is_string($repository['url'] ?? null)) {
                $url = $repository['url'];

                if (!str_starts_with($url, '/')) {
                    $absolute = self::normalize($projectPath . '/' . $url);
                    $url = str_starts_with($absolute . '/', rtrim($projectPath, '/') . '/') ? $url : $absolute;
                }

                $options = \is_array($repository['options'] ?? null) ? $repository['options'] : [];
                $repository = ['type' => 'path', 'url' => $url, 'options' => ['symlink' => false] + $options];
                $repository['options']['symlink'] = false;
            }

            $repositories[$key] = $repository;
        }

        if ([] !== $repositories) {
            $composer['repositories'] = $repositories;
        }

        unset($composer['require-dev'], $composer['autoload-dev']);

        return $composer;
    }

    /**
     * Copies the project without what a build leaves out.
     */
    public static function copy(string $from, string $to, bool $withContent): void
    {
        $leftOut = $withContent ? self::LEFT_OUT : [...self::LEFT_OUT, ...self::CONTENT];
        mkdir($to, 0o775, true);
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        $skip = null;

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($from) + 1);

            if (null !== $skip && str_starts_with($relative . '/', $skip . '/')) {
                continue;
            }

            if (\in_array($relative, $leftOut, true) || \in_array($file->getFilename(), self::LEFT_OUT_ANYWHERE, true)) {
                $skip = $relative;

                continue;
            }

            $target = $to . '/' . $relative;

            if ($file->isLink()) {
                throw new RuntimeException(\sprintf('%s is a symlink. A build copies files only, so replace it or leave it out.', $file->getPathname()));
            }

            if ($file->isDir()) {
                @mkdir($target, 0o775, true);
            } else {
                copy($file->getPathname(), $target);
                chmod($target, $file->getPerms() & 0o777);
            }
        }
    }

    /**
     * APP_SECRET from .env of the project, so images keep their addresses.
     *
     * @return array{APP_SECRET: string|null}
     */
    private static function environment(string $project): array
    {
        $file = $project . '/.env';
        $values = is_file($file) ? new Dotenv()->parse((string) file_get_contents($file), $file) : [];
        $secret = $values['APP_SECRET'] ?? null;

        return ['APP_SECRET' => \is_string($secret) && '' !== trim($secret) ? trim($secret) : null];
    }

    private static function envLive(?string $secret): string
    {
        $database = Installer::DEFAULT_DATABASE_URL;

        return <<<ENV
            # The environment of this website on the server. Rename this file to
            # .env there and never put it into Git.
            APP_ENV=prod

            # The same as in the workbench, so images keep their addresses.
            APP_SECRET={$secret}

            # SQLite in storage/, or MySQL and MariaDB, e.g.
            # DATABASE_URL="mysql://user:password@localhost:3306/database?serverVersion=8.0"
            DATABASE_URL='{$database}'

            # Mails of forms and notifications go out over the SMTP server of the
            # backend (Settings, Email), else with mail() of PHP. MAILER_DSN goes
            # before both, e.g. smtp://user:password@smtp.example.org:587
            # (special characters in the password URL-encoded).
            # MAILER_DSN=native://default

            # Media and files from forms in S3-compatible buckets instead of the
            # web space, with frootbox/storage-s3 (decision 0268); then run
            # php bin/frootbox storage:push and storage:check.
            # STORAGE_DSN="s3://key:secret@host/private-bucket?region=…"
            # PUBLIC_STORAGE_DSN="s3://key:secret@host/public-bucket?region=…&url=https://media.example.org"

            ENV;
    }

    /**
     * What the website still lacks to run live.
     *
     * @return list<string>
     */
    public static function warnings(string $folder): array
    {
        $file = $folder . '/config/app.yaml';
        $config = is_file($file) ? Yaml::parseFile($file) : null;
        $app = \is_array($config) && \is_array($config['app'] ?? null) ? $config['app'] : [];
        $url = $app['url'] ?? null;

        return \is_string($url) && '' !== trim($url) ? [] : ['config/app.yaml has no app.url. Live, the website then has no canonical links, no sitemap.xml and no tags for sharing. Set it to the address of the website, e.g. https://www.example.org.'];
    }

    private static function deployGuide(Project $project, bool $withContent): string
    {
        $content = $withContent
            ? 'This build contains the content: the database in storage/ and the media in storage/ and public/media/. Upload it only to a website that has no content of its own yet, it replaces everything.'
            : 'This build contains no content: storage/ is empty. When updating a running website, keep its storage/ and public/media/. For a new website, run "php bin/frootbox install" once, or build with --with-content.';

        return <<<MD
            # Upload {$project->title()}

            Built by "workbench build" (decision 0267). {$content}

            1. The server needs PHP 8.5 and runs this folder as it is. Composer is
               not needed. `app.url` in `config/app.yaml` is the address of the
               website; set it in the project before building.
            2. Upload the folder. **The web server shows `public/`, it is the
               document root**, never this folder: it holds `.env` and the
               database. Then https://<address>/.env must give 403 or 404. The
               `.htaccess` here locks this folder on Apache when the document
               root is wrong; other web servers need the right one.
            3. Rename `.env.live` to `.env` and fill it in. A website that runs
               already keeps its own `.env`. Mails go out over the SMTP server
               of the backend (Settings, Email), else with mail() of PHP; send
               a test mail there after the upload.
            4. The web server may write to `storage/` and `var/`.
            5. A cron job runs `php bin/frootbox schedule:run` every 5 minutes:
               scheduled publishing, backups, statistics, mails.
            6. After an update: `php bin/frootbox migrate` (it backs up the
               database first), then `php bin/frootbox cache:clear`. Without
               a console: delete `var/cache/`, then "Update now" under
               Extensions in the backend.

            `BUILD` names this build. For the next update,
            `workbench build <project> --since <that name>` also makes a
            folder with only what changed since.

            MD;
    }

    /**
     * The folder of an earlier build of a project, by its name (as in BUILD
     * on the server) or path.
     */
    public static function earlier(Project $project, string $builds, string $since): string
    {
        $since = rtrim(trim($since), '/');
        $folder = str_contains($since, '/') ? $since : $builds . '/' . $since;

        if (!str_starts_with(basename($folder), self::prefix($project))) {
            throw new RuntimeException(\sprintf('%s is no build of %s.', basename($folder), $project->title()));
        }

        if (!is_dir($folder)) {
            throw new RuntimeException(\sprintf('There is no build %s. The diff needs the folder of the build that runs on the server.', $folder));
        }

        return $folder;
    }

    private static function prefix(Project $project): string
    {
        return str_replace('.', '-', $project->name) . '-';
    }

    /**
     * Only what is new or changed in a build since an earlier one, for a
     * small upload: <build>-since-<time of the earlier>/, and next to it a
     * list of the files that are gone.
     *
     * @return array{folder: string, zip: string|null, files: int, deleted: list<string>, list: string|null}
     */
    public static function diff(string $earlier, string $build, bool $zip = false): array
    {
        foreach ([$earlier, $build] as $folder) {
            if (!is_dir($folder)) {
                throw new RuntimeException(\sprintf('There is no build %s.', $folder));
            }
        }

        $since = 1 === preg_match('/\d{8}-\d{6}$/', basename($earlier), $match) ? $match[0] : basename($earlier);
        $target = $build . '-since-' . $since;

        if (file_exists($target)) {
            throw new RuntimeException(\sprintf('%s exists already.', $target));
        }

        mkdir($target, 0o775, true);
        $before = self::files($earlier);
        $after = self::files($build);
        $count = 0;

        foreach ($after as $file) {
            $old = $earlier . '/' . $file;
            $new = $build . '/' . $file;

            if (is_file($old) && filesize($old) === filesize($new) && hash_file('xxh128', $old) === hash_file('xxh128', $new)) {
                continue;
            }

            if (!is_dir(\dirname($target . '/' . $file))) {
                mkdir(\dirname($target . '/' . $file), 0o775, true);
            }

            copy($new, $target . '/' . $file);
            chmod($target . '/' . $file, fileperms($new) & 0o777);
            ++$count;
        }

        $deleted = array_values(array_diff($before, $after));
        $list = null;

        if ([] !== $deleted) {
            $list = $target . '.deleted.txt';
            file_put_contents($list, implode("\n", $deleted) . "\n");
        }

        return ['folder' => $target, 'zip' => $zip ? self::zip($target, $target . '.zip') : null, 'files' => $count, 'deleted' => $deleted, 'list' => $list];
    }

    /**
     * The files of a build, relative and sorted, without what the Finder leaves.
     *
     * @return list<string>
     */
    private static function files(string $folder): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && '.DS_Store' !== $entry->getFilename()) {
                $files[] = substr($entry->getPathname(), \strlen($folder) + 1);
            }
        }

        sort($files, \SORT_STRING);

        return $files;
    }

    private static function zip(string $folder, string $file): string
    {
        $zip = new ZipArchive();

        if (true !== $zip->open($file, ZipArchive::CREATE | ZipArchive::EXCL)) {
            throw new RuntimeException(\sprintf('%s cannot be written.', $file));
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($files as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            $relative = substr($entry->getPathname(), \strlen($folder) + 1);

            if ($entry->isDir()) {
                $zip->addEmptyDir($relative);

                continue;
            }

            $zip->addFile($entry->getPathname(), $relative);
            $zip->setExternalAttributesName($relative, ZipArchive::OPSYS_UNIX, (0o100000 | ($entry->getPerms() & 0o777)) << 16);
        }

        $zip->close();

        return $file;
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }

    /**
     * A path without "." and "..", the glob of a path repository kept.
     */
    private static function normalize(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }

            if ('..' === $part) {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return '/' . implode('/', $parts);
    }
}
