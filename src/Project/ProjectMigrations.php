<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Project;

use Doctrine\DBAL\Connection;
use Frootbox\Core\Application;
use Frootbox\Core\Database\Migrator;
use RuntimeException;

/**
 * Migrations of a project that the current core brings (decision 0254).
 * Before migrating an SQLite database, a copy goes to storage/backups/
 * like the backups of decision 0183.
 */
final readonly class ProjectMigrations
{
    public function __construct(
        private Application $application,
        private string $projectRoot,
    ) {}

    public function pending(): int
    {
        return $this->migrator()->pending();
    }

    /**
     * Whether the database is a file that is copied before migrating.
     */
    public function copies(): bool
    {
        return null !== $this->sqliteFile();
    }

    /**
     * Copies an SQLite database and runs the open migrations.
     *
     * @return array{ran: list<string>, backup: string|null} The versions and the copy, relative to the project
     */
    public function migrate(): array
    {
        $backup = null;

        if (null !== $this->sqliteFile() && $this->pending() > 0) {
            $directory = $this->projectRoot . '/storage/backups';

            if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
                throw new RuntimeException(\sprintf('Could not create %s.', $directory));
            }

            $backup = 'storage/backups/' . date('Y-m-d-His') . '-migrate.sqlite';
            // A consistent copy even while the file is open, unlike copy().
            $this->connection()->executeStatement('VACUUM INTO ?', [$this->projectRoot . '/' . $backup]);
        }

        return ['ran' => $this->migrator()->migrate(), 'backup' => $backup];
    }

    private function sqliteFile(): ?string
    {
        $params = $this->connection()->getParams();
        $driver = $params['driver'] ?? null;
        $path = $params['path'] ?? null;

        return \in_array($driver, ['pdo_sqlite', 'sqlite3'], true) && \is_string($path) && ':memory:' !== $path && true !== ($params['memory'] ?? false) ? $path : null;
    }

    private function migrator(): Migrator
    {
        $migrator = $this->application->container()->get(Migrator::class);

        if (!$migrator instanceof Migrator) {
            throw new RuntimeException('The container has no migrator.');
        }

        return $migrator;
    }

    private function connection(): Connection
    {
        $connection = $this->application->container()->get(Connection::class);

        if (!$connection instanceof Connection) {
            throw new RuntimeException('The container has no database connection.');
        }

        return $connection;
    }
}
