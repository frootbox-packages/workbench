<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Tests;

use Frootbox\Workbench\CentralComposer;
use Frootbox\Workbench\Config;
use Frootbox\Workbench\Project\Projects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Projects::class)]
#[CoversClass(Config::class)]
#[CoversClass(CentralComposer::class)]
final class ProjectsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Folder::create();
        Folder::write($this->root . '/workbench.yaml', "domain: fb.localhost\nmonorepo: monorepo\nroots: [.]\nprojects:\n  sandbox: monorepo/sandbox\n");
        Folder::write($this->root . '/workbench.local.yaml', "roots: [more]\n");
        Folder::project($this->root . '/Holl/Holl24');
        Folder::project($this->root . '/HerrUndFrauPixel/FleischereiVolmer');
        Folder::project($this->root . '/PixelFabrik/FleischereiVolmer');
        Folder::project($this->root . '/more/Acme/Frootbox DE');
        Folder::project($this->root . '/monorepo/sandbox');
        // Not projects: shared packages of a vendor and a folder without config/.
        Folder::write($this->root . '/Holl/packages/HollExt/composer.json', '{"name": "holl/ext"}');
        Folder::write($this->root . '/Holl/Notes/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        Folder::remove($this->root);
    }

    public function testFindsProjectsBelowTheRootsAndByName(): void
    {
        $projects = new Projects(Config::load($this->root));

        self::assertSame(
            ['fleischereivolmer.herrundfraupixel', 'fleischereivolmer.pixelfabrik', 'frootbox-de.acme', 'holl24.holl', 'sandbox'],
            array_keys($projects->all()),
        );
        self::assertSame('Holl/Holl24', $projects->get('holl24')?->title());
        self::assertSame('sandbox', $projects->get('sandbox')?->title());
    }

    public function testShortNamesOnlyWhileUnique(): void
    {
        $projects = new Projects(Config::load($this->root));

        self::assertSame('holl24.fb.localhost', $projects->host($projects->all()['holl24.holl']));
        self::assertSame('frootbox-de.fb.localhost', $projects->host($projects->all()['frootbox-de.acme']));
        self::assertSame('fleischereivolmer.pixelfabrik.fb.localhost', $projects->host($projects->all()['fleischereivolmer.pixelfabrik']));
        self::assertNull($projects->get('fleischereivolmer'), 'Two projects share the short name.');
    }

    public function testChoosesTheProjectByHost(): void
    {
        $projects = new Projects(Config::load($this->root));

        self::assertSame('holl24.holl', $projects->byHost('holl24.fb.localhost')?->name);
        self::assertSame('holl24.holl', $projects->byHost('Holl24.Holl.fb.localhost:8300')?->name);
        self::assertSame('fleischereivolmer.herrundfraupixel', $projects->byHost('fleischereivolmer.herrundfraupixel.fb.localhost')?->name);
        self::assertNull($projects->byHost('fleischereivolmer.fb.localhost'));
        self::assertNull($projects->byHost('holl24.example.com'));
        self::assertTrue($projects->isStart('fb.localhost:8300'));
        self::assertFalse($projects->isStart('holl24.fb.localhost'));
    }

    public function testTheComposerJsonOfTheWorkbench(): void
    {
        Folder::write($this->root . '/monorepo/packages/core/composer.json', '{"name": "frootbox/core"}');
        Folder::write($this->root . '/monorepo/packages/modules/news/composer.json', '{"name": "frootbox/news"}');
        Folder::write($this->root . '/composer.json', '{"require": {"acme/library": "^2.0"}}');

        self::assertTrue(new CentralComposer($this->root, $this->root . '/monorepo')->write());
        $data = json_decode((string) file_get_contents($this->root . '/composer.json'), true);
        self::assertIsArray($data);
        self::assertSame(['php' => '>=8.5', 'acme/library' => '^2.0', 'frootbox/core' => '@dev', 'frootbox/news' => '@dev'], $data['require'] ?? null, 'Packages added by hand stay.');
        self::assertIsArray($data['repositories'] ?? null);
        self::assertSame(
            ['monorepo/packages/*', 'monorepo/packages/modules/*', 'monorepo/packages/themes/*', 'monorepo/packages/tools/*', '*/packages/*'],
            array_column($data['repositories'], 'url'),
        );
        self::assertFalse(new CentralComposer($this->root, $this->root . '/monorepo')->write(), 'Nothing changes a second time.');
    }

    public function testRelativePaths(): void
    {
        self::assertSame('../frootbox2', CentralComposer::relative('/Users/jane/Sites/frootbox-sites', '/Users/jane/Sites/frootbox2'));
        self::assertSame('../../../frootbox2', CentralComposer::relative('/Users/jane/Sites/frootbox-sites/Holl/Holl24', '/Users/jane/Sites/frootbox2'));
        self::assertSame('.', CentralComposer::relative('/a/b', '/a/b'));
    }
}
