<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Tests;

use Frootbox\Workbench\Console\InitCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(InitCommand::class)]
final class InitCommandTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = Folder::create();
    }

    protected function tearDown(): void
    {
        Folder::remove($this->root);
    }

    public function testWritesWhatTheHostNeeds(): void
    {
        $monorepo = \dirname(__DIR__, 4);
        $tester = new CommandTester(new InitCommand());

        self::assertSame(0, $tester->execute(['folder' => $this->root, '--monorepo' => $monorepo]));
        self::assertFileExists($this->root . '/workbench.yaml');
        self::assertFileExists($this->root . '/composer.json');
        self::assertFileExists($this->root . '/public/index.php');
        self::assertFileEquals($monorepo . '/skeleton/public/.htaccess', $this->root . '/public/.htaccess', 'Without it Apache answers 404 for every page.');
        self::assertStringContainsString('ServerAlias *.fb.localhost', $tester->getDisplay());
    }

    public function testKeepsFilesThatExist(): void
    {
        Folder::write($this->root . '/public/.htaccess', "# own rules\n");

        new CommandTester(new InitCommand())->execute(['folder' => $this->root, '--monorepo' => \dirname(__DIR__, 4)]);

        self::assertStringEqualsFile($this->root . '/public/.htaccess', "# own rules\n");
    }
}
