<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Project;

use Frootbox\Core\Application;
use Frootbox\Core\Backup\BackupManager;
use Frootbox\Core\Backup\BackupReason;
use Frootbox\Core\Database\Migrator;
use RuntimeException;

/**
 * Migrations of a project that the current core brings (decision 0254),
 * after a backup in storage/backups/ like bin/frootbox migrate
 * (decision 0183).
 */
final readonly class ProjectMigrations
{
    public function __construct(private Application $application) {}

    public function pending(): int
    {
        return $this->migrator()->pending();
    }

    /**
     * Whether there is a database to back up before migrating.
     */
    public function copies(): bool
    {
        return $this->backups()->available();
    }

    /**
     * Backs up the database and runs the open migrations.
     *
     * @return array{ran: list<string>, backup: string|null} The versions and the backup, relative to the project
     */
    public function migrate(): array
    {
        $backup = null;

        if ($this->copies() && $this->pending() > 0) {
            $backup = 'storage/backups/' . $this->backups()->create(BackupReason::Migrate)->name;
        }

        return ['ran' => $this->migrator()->migrate(), 'backup' => $backup];
    }

    private function migrator(): Migrator
    {
        return self::service($this->application, Migrator::class);
    }

    private function backups(): BackupManager
    {
        return self::service($this->application, BackupManager::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function service(Application $application, string $class): object
    {
        $service = $application->container()->get($class);

        return $service instanceof $class ? $service : throw new RuntimeException(\sprintf('The container has no %s.', $class));
    }
}
