<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench;

/**
 * The time zone of the developer's computer for the workbench. PHP does
 * not read the system setting and runs in date.timezone of php.ini, which
 * MAMP sets to UTC, so the names of builds and the time of a check were
 * off by an hour or two. A website with "app.timezone" still sets its own
 * when it starts (decision 0227).
 */
final class LocalTimeZone
{
    /**
     * Uses the zone of the computer, else keeps the one of php.ini.
     */
    public static function apply(): void
    {
        $link = @readlink('/etc/localtime');
        $zone = self::detect(getenv('TZ'), false === $link ? null : $link);

        if (null !== $zone) {
            date_default_timezone_set($zone);
        }
    }

    /**
     * The zone from $TZ, e.g. "Europe/Berlin" or ":Europe/Berlin", else
     * from the link /etc/localtime on macOS and Linux, e.g.
     * "/var/db/timezone/zoneinfo/Europe/Berlin". Null if neither names one
     * PHP knows.
     */
    public static function detect(string|false|null $variable, ?string $localtime): ?string
    {
        $candidates = [];

        if (\is_string($variable) && '' !== $variable) {
            $candidates[] = ltrim($variable, ':');
        }

        if (null !== $localtime && 1 === preg_match('~zoneinfo/(.+)$~', $localtime, $match)) {
            $candidates[] = $match[1];
        }

        $known = timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC);

        foreach ($candidates as $candidate) {
            if (\in_array($candidate, $known, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
