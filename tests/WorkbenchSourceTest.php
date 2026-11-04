<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Tests;

use Frootbox\Core\Extension\ExtensionException;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Workbench\Extension\CentralPackages;
use Frootbox\Workbench\Extension\ComposerPackage;
use Frootbox\Workbench\Extension\ProjectAutoloader;
use Frootbox\Workbench\Extension\WorkbenchSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkbenchSource::class)]
#[CoversClass(CentralPackages::class)]
#[CoversClass(ComposerPackage::class)]
#[CoversClass(ProjectAutoloader::class)]
final class WorkbenchSourceTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = Folder::create();
    }

    protected function tearDown(): void
    {
        Folder::remove($this->project);
    }

    public function testActiveIsWhatTheProjectRequiresLikeLive(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $namespace = 'WorkbenchTest' . $suffix;
        Folder::project($this->project, [
            'require' => ['php' => '>=8.5', 'ext-intl' => '*', 'frootbox/forms' => '^0.1@dev', 'acme/website' => '*'],
            'require-dev' => ['frootbox/migrate-frootbox1' => '^0.1@dev'],
            'repositories' => [['type' => 'path', 'url' => '../../frootbox2/packages/*'], ['type' => 'path', 'url' => 'extensions/*']],
        ]);
        Folder::write($this->project . '/extensions/website/composer.json', json_encode([
            'name' => 'acme/website',
            'require' => ['frootbox/theme-base' => '*'],
            'autoload' => ['psr-4' => [$namespace . '\\' => 'src/']],
        ], \JSON_THROW_ON_ERROR));
        Folder::write($this->project . '/extensions/website/frootbox.yaml', "title: Acme\ntheme:\n  parent: frootbox/theme-base\n");
        Folder::write($this->project . '/extensions/website/src/Helper.php', "<?php\nnamespace {$namespace};\nfinal class Helper {}\n");

        $source = new WorkbenchSource(new CentralPackages(\dirname(__DIR__, 4) . '/vendor'), $this->project);
        $packages = $source->packages();
        $names = array_map(static fn(ExtensionPackage $package): string => $package->name, $packages);

        self::assertSame([], $source->missing());
        self::assertContains('frootbox/forms', $names);
        self::assertContains('frootbox/theme-base', $names, 'Required by the project extension.');
        self::assertContains('acme/website', $names);
        self::assertContains('frootbox/migrate-frootbox1', $names, 'The workbench is development, require-dev counts.');
        self::assertNotContains('frootbox/news', $names, 'In the central vendor/, but not required.');
        self::assertNotContains('frootbox/core', $names, 'No frootbox.yaml.');

        $website = array_values(array_filter($packages, static fn(ExtensionPackage $package): bool => 'acme/website' === $package->name))[0];
        self::assertTrue($website->isProject);
        self::assertTrue(class_exists($namespace . '\Helper'), 'The classes of project extensions load at runtime.');
    }

    public function testNamesMissingPackages(): void
    {
        Folder::project($this->project, ['require' => ['frootbox/core' => '*', 'acme/missing' => '*', 'psr/http-message-implementation' => '*']]);
        $source = new WorkbenchSource(new CentralPackages(\dirname(__DIR__, 4) . '/vendor'), $this->project);

        self::assertSame(['acme/missing'], $source->missing(), 'A name another package provides is there.');
        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('acme/missing');
        $source->packages();
    }
}
