<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Tests;

use Frootbox\Core\Application;
use Frootbox\Workbench\Check\ProjectCheck;
use Frootbox\Workbench\Console\Environment;
use Frootbox\Workbench\Console\NewCommand;
use Frootbox\Workbench\Http\Front;
use Frootbox\Workbench\Http\Pages;
use Frootbox\Workbench\Http\StaticFile;
use Frootbox\Workbench\Project\Project;
use Frootbox\Workbench\Project\ProjectMigrations;
use Frootbox\Workbench\Workbench;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A real website in a workbench whose vendor/ is the one of the monorepo,
 * created with "new" once for all tests.
 */
#[CoversClass(Workbench::class)]
#[CoversClass(Front::class)]
#[CoversClass(Pages::class)]
#[CoversClass(StaticFile::class)]
#[CoversClass(ProjectMigrations::class)]
#[CoversClass(ProjectCheck::class)]
#[CoversClass(NewCommand::class)]
#[CoversClass(Environment::class)]
final class WorkbenchTest extends TestCase
{
    private const array ADMIN = ['FROOTBOX_ADMIN_EMAIL' => 'admin@example.test', 'FROOTBOX_ADMIN_NAME' => 'Admin', 'FROOTBOX_ADMIN_PASSWORD' => 'workbench-test-password-1'];

    private static string $root = '';

    /** Booting a project sets the time zone of PHP for all tests after it. */
    private static string $timezone = 'UTC';

    private string $testTimezone = 'UTC';

    /** @var array<string, string|false> */
    private static array $environment = [];

    public static function setUpBeforeClass(): void
    {
        self::$timezone = date_default_timezone_get();
        $monorepo = \dirname(__DIR__, 4);
        self::$root = Folder::create();
        Folder::write(self::$root . '/workbench.yaml', "domain: fb.localhost\nmonorepo: {$monorepo}\nroots: [.]\n");
        symlink($monorepo . '/vendor', self::$root . '/vendor');

        // The shell wins over sandbox.env of the monorepo.
        foreach (self::ADMIN as $name => $value) {
            self::$environment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        $tester = new CommandTester(new NewCommand(static fn(): Workbench => Workbench::fromRoot(self::$root)));
        $tester->execute(['name' => 'Acme/Demo', '--title' => 'Demo GmbH']);
        date_default_timezone_set(self::$timezone);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$environment as $name => $value) {
            putenv(false === $value ? $name : $name . '=' . $value);
        }

        Folder::remove(self::$root);
        date_default_timezone_set(self::$timezone);
    }

    protected function setUp(): void
    {
        $this->testTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->testTimezone);
    }

    public function testNewProjectsAnswerAtOnce(): void
    {
        $project = $this->project();
        self::assertFileExists($project->path . '/.env');
        self::assertFileExists($project->path . '/extensions/demo-theme/frootbox.yaml');
        self::assertStringContainsString('theme: demo/theme', (string) file_get_contents($project->path . '/config/app.yaml'));
        self::assertStringContainsString('"url": "extensions/*"', (string) file_get_contents($project->path . '/composer.json'));
        self::assertFileExists($project->path . '/.htaccess');
        self::assertFileDoesNotExist($project->path . '/LICENSE', 'The website belongs to its author, not under the license of Frootbox.');
        self::assertFileDoesNotExist($project->path . '/README.md');
        self::assertFileExists($project->path . '/AGENTS.md', 'An agent knows how to build the website from the start.');

        $start = $this->request('http://fb.localhost/');
        self::assertSame(200, $start->getStatusCode());
        self::assertStringContainsString('href="http://demo.fb.localhost"', (string) $start->getBody());

        $page = $this->request('http://demo.fb.localhost/');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Demo GmbH', (string) $page->getBody());

        self::assertSame(200, $this->request('http://demo.acme.fb.localhost/')->getStatusCode(), 'The long form works too.');
        self::assertSame(404, $this->request('http://other.fb.localhost/')->getStatusCode());
    }

    public function testDeliversFilesOfThePublicFolderOnly(): void
    {
        $project = $this->project();
        Folder::write($project->path . '/public/media/hello.txt', 'Hello');

        $file = $this->request('http://demo.fb.localhost/media/hello.txt');
        self::assertSame(200, $file->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $file->getHeaderLine('Content-Type'));
        self::assertSame('Hello', (string) $file->getBody());

        $workbench = Workbench::fromRoot(self::$root);
        self::assertInstanceOf(Application::class, new Front($workbench)->dispatch(new ServerRequest('GET', 'http://demo.fb.localhost/index.php')), 'PHP goes to Frootbox, never as a file.');
        self::assertInstanceOf(Application::class, new Front($workbench)->dispatch(new ServerRequest('GET', 'http://demo.fb.localhost/media/../../composer.json')));
    }

    public function testOpenMigrationsWaitForTheButton(): void
    {
        $project = $this->project();
        $this->addMigration($project);

        $notice = $this->request('http://demo.fb.localhost/about');
        self::assertSame(503, $notice->getStatusCode());
        self::assertStringContainsString('<strong>1 migration</strong>', (string) $notice->getBody());
        self::assertStringContainsString('storage/backups/', (string) $notice->getBody());

        self::assertSame(403, $this->request('http://demo.fb.localhost/_workbench/migrate', 'POST', ['Sec-Fetch-Site' => 'cross-site'])->getStatusCode());
        self::assertSame(403, $this->request('http://demo.fb.localhost/_workbench/migrate', 'POST')->getStatusCode(), 'Without a sign of the origin.');

        $done = $this->request('http://demo.fb.localhost/_workbench/migrate', 'POST', ['Origin' => 'http://demo.fb.localhost']);
        self::assertSame(303, $done->getStatusCode());
        self::assertSame('/', $done->getHeaderLine('Location'));
        $backups = glob($project->path . '/storage/backups/*-migrate.sqlite.gz');
        self::assertIsArray($backups);
        self::assertCount(1, $backups, 'A backup before migrating.');
        self::assertSame(200, $this->request('http://demo.fb.localhost/')->getStatusCode());

        $check = new ProjectCheck(Workbench::fromRoot(self::$root))->run($project);
        self::assertSame([], $check['problems']);
        self::assertTrue($check['ok']);
        self::assertSame(0, $check['pending']);
    }

    public function testCheckNamesMissingPackages(): void
    {
        $project = $this->project();
        $composer = (string) file_get_contents($project->path . '/composer.json');
        file_put_contents($project->path . '/composer.json', str_replace('"frootbox/backend"', '"acme/missing": "*", "frootbox/backend"', $composer));

        try {
            $check = new ProjectCheck(Workbench::fromRoot(self::$root))->run($project);
            $page = $this->request('http://demo.fb.localhost/');
        } finally {
            file_put_contents($project->path . '/composer.json', $composer);
        }

        self::assertFalse($check['ok']);
        self::assertSame(['Packages missing: acme/missing'], $check['problems']);
        self::assertSame(500, $page->getStatusCode());
        self::assertStringContainsString('acme/missing', (string) $page->getBody());
    }

    public function testProjectsKeepTheirOwnDatabase(): void
    {
        $workbench = Workbench::fromRoot(self::$root);
        $environment = $workbench->environment($this->project(), ['DATABASE_URL' => 'pdo-mysql://shared/db', 'APP_ENV' => 'prod', 'HOME' => '/home/jane']);

        self::assertSame(['HOME' => '/home/jane'], $environment);
    }

    private function project(): Project
    {
        $project = Workbench::fromRoot(self::$root)->projects->get('demo');
        self::assertNotNull($project);

        return $project;
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $url, string $method = 'GET', array $headers = []): ResponseInterface
    {
        $answer = new Front(Workbench::fromRoot(self::$root))->dispatch(new ServerRequest($method, $url, $headers));

        return $answer instanceof Application ? $answer->handle(new ServerRequest($method, $url, $headers)) : $answer;
    }

    /**
     * A project extension with one migration, as the core brings them with an update.
     */
    private function addMigration(Project $project): void
    {
        $extension = $project->path . '/extensions/notes';
        Folder::write($extension . '/composer.json', (string) json_encode(['name' => 'demo/notes', 'autoload' => ['psr-4' => ['Demo\Notes\Migrations\\' => 'migrations/']]], \JSON_UNESCAPED_SLASHES));
        Folder::write($extension . '/frootbox.yaml', "title: Notes\n");
        Folder::write($extension . '/migrations/Version20990101000000.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Demo\Notes\Migrations;

            use Doctrine\DBAL\Schema\Schema;
            use Frootbox\Database\Migration;
            use Frootbox\Database\Schema\Blueprint;

            final class Version20990101000000 extends Migration
            {
                public function up(Schema $schema): void
                {
                    $this->createTable('demo_notes_notes', function (Blueprint $table): void {
                        $table->ulid();
                        $table->string('text', 200);
                    });
                }
            }
            PHP);

        $composer = (string) file_get_contents($project->path . '/composer.json');
        file_put_contents($project->path . '/composer.json', str_replace('"demo/theme": "*"', '"demo/notes": "*", "demo/theme": "*"', $composer));
    }
}
