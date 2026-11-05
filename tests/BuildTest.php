<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Tests;

use Frootbox\Workbench\Build\Builder;
use Frootbox\Workbench\Project\Project;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Builder::class)]
final class BuildTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = Folder::create();
    }

    protected function tearDown(): void
    {
        Folder::remove($this->folder);
    }

    public function testComposerCopiesThePackagesForTheBuild(): void
    {
        $composer = Builder::composerForBuild([
            'name' => 'acme/site',
            'repositories' => [
                ['type' => 'path', 'url' => '../../../frootbox2/packages/modules/*', 'options' => ['symlink' => true]],
                ['type' => 'path', 'url' => 'extensions/*'],
                ['type' => 'path', 'url' => '/opt/packages/*'],
                ['type' => 'composer', 'url' => 'https://repo.example.org'],
            ],
            'require' => ['frootbox/core' => '^0.1@dev'],
            'require-dev' => ['frootbox/migrate-frootbox1' => '^0.1@dev'],
            'autoload-dev' => ['psr-4' => ['Acme\\Tests\\' => 'tests/']],
        ], '/Users/me/Sites/frootbox-sites/Acme/Site');

        self::assertSame([
            ['type' => 'path', 'url' => '/Users/me/Sites/frootbox2/packages/modules/*', 'options' => ['symlink' => false]],
            ['type' => 'path', 'url' => 'extensions/*', 'options' => ['symlink' => false]],
            ['type' => 'path', 'url' => '/opt/packages/*', 'options' => ['symlink' => false]],
            ['type' => 'composer', 'url' => 'https://repo.example.org'],
        ], $composer['repositories'], 'Outside the project absolute, inside it relative, so Composer remembers extensions/.');
        self::assertSame(['frootbox/core' => '^0.1@dev'], $composer['require']);
        self::assertArrayNotHasKey('require-dev', $composer, 'The import of Frootbox 1 stays home.');
        self::assertArrayNotHasKey('autoload-dev', $composer);
    }

    public function testCopiesTheProjectWithoutWhatStaysHome(): void
    {
        $project = $this->folder . '/Acme/Site';

        foreach (['composer.json', 'composer.lock', '.env', '.env.example', 'config/app.yaml', 'bin/frootbox', 'public/index.php', 'public/.htaccess', 'public/assets/acme/site.css', 'public/media/1/a.jpg', 'storage/database.sqlite', 'storage/media/a.jpg', 'storage/backups/old.sqlite.gz', 'vendor/autoload.php', 'var/log/dev.log', 'migration/mapping.yaml', 'extensions/acme-site/frootbox.yaml', 'extensions/acme-site/node_modules/x/index.js', '.git/HEAD'] as $file) {
            Folder::write($project . '/' . $file, $file);
        }

        Builder::copy($project, $this->folder . '/code', withContent: false);
        Builder::copy($project, $this->folder . '/content', withContent: true);

        self::assertSame(['.env.example', 'bin/frootbox', 'composer.json', 'config/app.yaml', 'extensions/acme-site/frootbox.yaml', 'public/.htaccess', 'public/index.php'], self::files($this->folder . '/code'));
        self::assertSame(['.env.example', 'bin/frootbox', 'composer.json', 'config/app.yaml', 'extensions/acme-site/frootbox.yaml', 'public/.htaccess', 'public/index.php', 'public/media/1/a.jpg', 'storage/database.sqlite', 'storage/media/a.jpg'], self::files($this->folder . '/content'), 'The content only on request, never the backups.');
    }

    public function testWarnsWithoutTheAddressOfTheWebsite(): void
    {
        Folder::write($this->folder . '/config/app.yaml', "app:\n  name: Acme\n");
        self::assertStringContainsString('no app.url', Builder::warnings($this->folder)[0] ?? '');

        Folder::write($this->folder . '/config/app.yaml', "app:\n  name: Acme\n  url: https://www.example.org\n");
        self::assertSame([], Builder::warnings($this->folder));
    }

    public function testRefusesASymlinkInTheProject(): void
    {
        $project = $this->folder . '/Site';
        Folder::write($project . '/config/app.yaml', 'app: {}');
        symlink($project . '/config', $project . '/linked');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is a symlink');

        Builder::copy($project, $this->folder . '/build', withContent: false);
    }

    public function testStopsWhenComposerFails(): void
    {
        $project = $this->folder . '/Acme/Site';
        Folder::project($project);
        $builder = new Builder(static fn(): int => 1, static fn(): int => 0, static function (): void {});

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Composer failed');

        $builder->build(new Project('site.acme', 'site', $project, 'Acme', 'Site'), $this->folder . '/builds');
    }

    public function testLocksTheFolderOfTheWebsiteForAWrongDocumentRoot(): void
    {
        $builder = new Builder(static fn(): int => 0, static fn(): int => 0, static function (): void {});
        $project = $this->folder . '/Acme/Site';
        Folder::project($project);

        $build = $builder->build(new Project('site.acme', 'site', $project, 'Acme', 'Site'), $this->folder . '/builds');

        self::assertSame(Builder::GUARD, file_get_contents($build['folder'] . '/.htaccess'), 'Nothing outside public/ is served, not .env, not the database (decision 0270).');
        self::assertStringContainsString('must give 403 or 404', (string) file_get_contents($build['folder'] . '/DEPLOY.md'));
        self::assertSame(Builder::GUARD, file_get_contents(\dirname(__DIR__, 4) . '/skeleton/.htaccess'), 'New projects have the same.');

        // A project with one of its own keeps it.
        Folder::write($project . '/.htaccess', "Require all denied\n");
        $build = $builder->build(new Project('site.acme', 'site', $project, 'Acme', 'Site'), $this->folder . '/builds', now: new \DateTimeImmutable('2026-01-01'));
        self::assertSame("Require all denied\n", file_get_contents($build['folder'] . '/.htaccess'));
    }

    public function testACopyWithContentLeavesTheConnectionToTheHubOut(): void
    {
        $project = $this->folder . '/Acme/Site';
        Folder::project($project);
        $calls = [];
        $builder = new Builder(static fn(): int => 0, static function (string $folder, array $arguments) use (&$calls): int {
            $calls[] = $arguments;

            return 0;
        }, static function (): void {});

        $builder->build(new Project('site.acme', 'site', $project, 'Acme', 'Site'), $this->folder . '/builds');
        self::assertNotContains(['hub:disconnect', '--local', '--forget-key'], $calls, 'Without content there is no connection.');

        $builder->build(new Project('site.acme', 'site', $project, 'Acme', 'Site'), $this->folder . '/builds', withContent: true, now: new \DateTimeImmutable('2026-01-01'));
        self::assertNotContains(['hub:disconnect', '--local', '--forget-key'], $calls, 'Nor without the database in storage/, e.g. with MySQL.');

        Folder::write($project . '/storage/database.sqlite', '');
        $builder->build(new Project('site.acme', 'site', $project, 'Acme', 'Site'), $this->folder . '/builds', withContent: true, now: new \DateTimeImmutable('2026-01-02'));
        self::assertContains(['hub:disconnect', '--local', '--forget-key'], $calls, 'Two installations never share the key of the website.');
    }

    /**
     * @return list<string>
     */
    private static function files(string $folder): array
    {
        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));

        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                $files[] = substr($item->getPathname(), \strlen($folder) + 1);
            }
        }

        sort($files);

        return $files;
    }
}
