<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Tests;

use Frootbox\Core\Media\MediaHandler;
use Frootbox\Workbench\Http\StaticFile;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StaticFile::class)]
final class StaticFileTest extends TestCase
{
    private string $public = '';

    protected function setUp(): void
    {
        $this->public = Folder::create();
    }

    protected function tearDown(): void
    {
        Folder::remove($this->public);
    }

    public function testAnSvgMayNotRunAnythingLikeLive(): void
    {
        Folder::write($this->public . '/media/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $response = StaticFile::respond($this->public, new ServerRequest('GET', 'http://demo.fb.localhost/media/logo.svg'));

        self::assertNotNull($response);
        self::assertSame(MediaHandler::SVG_POLICY, $response->getHeaderLine('Content-Security-Policy'), 'The same as the .htaccess of a website (decision 0182).');
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testOtherFilesKeepTheirHeaders(): void
    {
        Folder::write($this->public . '/media/photo.png', 'png');

        $response = StaticFile::respond($this->public, new ServerRequest('GET', 'http://demo.fb.localhost/media/photo.png'));

        self::assertNotNull($response);
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
    }
}
