<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Build;

use DateTimeImmutable;

/**
 * The last build started from the backend (decision 0291).
 */
final readonly class BuildRun
{
    public const string NONE = 'none';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    /**
     * @param string             $state      none, running, done or failed
     * @param string             $builds     Folder of the builds
     * @param string             $latest     Name of the fixed folder a diff goes to
     * @param string|null        $suggestion The build to start the next diff from
     * @param string|null        $server     The build noted as uploaded to the server
     * @param string|null        $since      The build the run made a diff from, null for a full build
     * @param string|null        $build      Name of the new build
     * @param string|null        $diff       Name of the folder with what changed
     * @param int|null           $files      Files in that folder
     * @param int|null           $gone       Files gone since the earlier build
     * @param list<string>       $output     The last lines of the output
     */
    public function __construct(
        public string $state,
        public string $builds,
        public string $latest,
        public ?string $suggestion = null,
        public ?string $server = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?string $since = null,
        public ?string $build = null,
        public ?string $diff = null,
        public ?int $files = null,
        public ?int $gone = null,
        public ?int $exit = null,
        public array $output = [],
    ) {}
}
