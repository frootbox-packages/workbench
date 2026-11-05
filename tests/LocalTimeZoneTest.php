<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Tests;

use Frootbox\Workbench\LocalTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalTimeZone::class)]
final class LocalTimeZoneTest extends TestCase
{
    public function testTheLinkOfMacOsNamesTheZone(): void
    {
        self::assertSame('Europe/Berlin', LocalTimeZone::detect(false, '/var/db/timezone/zoneinfo/Europe/Berlin'));
    }

    public function testTheLinkOfLinuxNamesTheZone(): void
    {
        self::assertSame('America/New_York', LocalTimeZone::detect(null, '/usr/share/zoneinfo/America/New_York'));
    }

    public function testTheVariableComesFirst(): void
    {
        self::assertSame('Asia/Tokyo', LocalTimeZone::detect(':Asia/Tokyo', '/usr/share/zoneinfo/Europe/Berlin'));
    }

    public function testAnUnknownVariableFallsBackToTheLink(): void
    {
        self::assertSame('Europe/Berlin', LocalTimeZone::detect('CET-1CEST', '/usr/share/zoneinfo/Europe/Berlin'));
    }

    public function testNothingKnownGivesNull(): void
    {
        self::assertNull(LocalTimeZone::detect('', '/etc/something'));
        self::assertNull(LocalTimeZone::detect(false, null));
    }
}
