<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Extension;

use Frootbox\Backend\Screen;
use Frootbox\Core\Extension\Extension;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Registry;
use Frootbox\Workbench\Build\MarkUploaded;
use Frootbox\Workbench\Build\ShowBuild;
use Frootbox\Workbench\Build\StartBuild;
use Frootbox\Workbench\Http\BuildHandler;

/**
 * What the workbench adds to each of its websites (decision 0291): the
 * build for upload from the backend. The package has no frootbox.yaml, so
 * it exists only in the workbench, never on a server or in a build.
 */
final class WorkbenchExtension implements Extension
{
    public const string PACKAGE = 'frootbox/workbench';

    public static function package(): ExtensionPackage
    {
        return new ExtensionPackage(self::PACKAGE, \dirname(__DIR__, 2), 'Workbench', 'Builds this website for upload from the backend, only in the workbench', self::class);
    }

    public function register(Registry $registry): void
    {
        $registry->action(StartBuild::class);
        $registry->action(MarkUploaded::class);
        $registry->query(ShowBuild::class);

        if (class_exists(Screen::class)) {
            $registry->mount('backend.path', '/cms')->get('/workbench', BuildHandler::class, 'frootbox.workbench.build');
            $registry->add(Screen::class, new Screen('Build', 'frootbox.workbench.build', Screen::ADMINISTRATION, 'arrow-up', StartBuild::PERMISSION, order: 126));
        }
    }
}
