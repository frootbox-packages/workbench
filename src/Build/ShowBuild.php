<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Build;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Config\Config;

/**
 * The last build started from the backend and the build to start from
 * next (decision 0291).
 *
 * @implements Query<BuildRun>
 */
#[AsQuery('frootbox/workbench/build.show', 'Shows the last build of this website for upload: running, done or failed, with its folders and output. Only in the workbench', permission: StartBuild::PERMISSION)]
final readonly class ShowBuild implements Query
{
    public function handle(Config $config): BuildRun
    {
        $runs = BuildRuns::of($config) ?? throw new ConflictException('This website does not run in the workbench.');

        return $runs->show();
    }
}
