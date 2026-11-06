<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Build;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Config\Config;
use RuntimeException;

/**
 * Notes that a build is on the server after its upload, so the next diff
 * starts from it (decision 0292).
 *
 * @implements Action<BuildRun>
 */
#[AsAction(self::NAME, 'Notes that a build of this website is uploaded to the server; the next build makes its diff from it. Only in the workbench', permission: StartBuild::PERMISSION, undoable: false)]
final readonly class MarkUploaded implements Action
{
    public const string NAME = 'frootbox/workbench/build.mark-uploaded';

    public function __construct(
        #[Description('Name of the build, e.g. holl24-holl-20261106-101003')]
        #[Length(1, 200)]
        public string $build,
    ) {}

    public function handle(Config $config): BuildRun
    {
        $runs = BuildRuns::of($config) ?? throw new ConflictException('This website does not run in the workbench.');

        try {
            return $runs->markUploaded($this->build);
        } catch (RuntimeException $error) {
            throw new InvalidInputException(self::NAME, ['/build' => $error->getMessage()]);
        }
    }
}
