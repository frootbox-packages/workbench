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
 * Builds this website for upload in the background, like
 * workbench build <project> --zip [--since <build> --latest] (decision 0291).
 *
 * @implements Action<BuildRun>
 */
#[AsAction(self::NAME, 'Builds this website as a folder to upload, in the background; with since also a folder with only what changed, always the same one. Only in the workbench', permission: self::PERMISSION, undoable: false, confirm: true)]
final readonly class StartBuild implements Action
{
    public const string NAME = 'frootbox/workbench/build.start';

    public const string PERMISSION = 'system.workbench';

    public function __construct(
        #[Description('The build that runs on the server, as in its file BUILD; empty for a full build')]
        #[Length(max: 200)]
        public ?string $since = null,
    ) {}

    public function handle(Config $config): BuildRun
    {
        $runs = BuildRuns::of($config) ?? throw new ConflictException('This website does not run in the workbench.');

        if ($runs->running()) {
            throw new ConflictException('A build of this website is running. Wait until it is done.');
        }

        $since = null === $this->since || '' === trim($this->since) ? null : trim($this->since);

        try {
            return $runs->start($since);
        } catch (RuntimeException $error) {
            throw new InvalidInputException(self::NAME, [null === $since ? '' : '/since' => $error->getMessage()]);
        }
    }
}
