<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Project;

/**
 * A website in the workbench: a folder with composer.json and
 * config/app.yaml, e.g. ~/Sites/frootbox-sites/Holl/Holl24.
 */
final readonly class Project
{
    /**
     * @param string      $name   Unique, the subdomain in long form, e.g. "holl24.holl" or "sandbox"
     * @param string      $short  The subdomain in short form, e.g. "holl24"; only usable if no other project has it
     * @param string      $path   Absolute folder
     * @param string|null $vendor Folder of the vendor, e.g. "Holl"; null for a project named in workbench.yaml
     * @param string      $site   Folder of the website, e.g. "Holl24", or the name from workbench.yaml
     */
    public function __construct(
        public string $name,
        public string $short,
        public string $path,
        public ?string $vendor,
        public string $site,
    ) {}

    /**
     * Whether it can be set up and run: .env exists.
     */
    public function installed(): bool
    {
        return is_file($this->path . '/.env');
    }

    /**
     * How to call it, e.g. "Holl/Holl24" or "sandbox".
     */
    public function title(): string
    {
        return null === $this->vendor ? $this->site : $this->vendor . '/' . $this->site;
    }

    /**
     * The folder below a root, e.g. "Holl/Holl24".
     */
    public function folder(): string
    {
        return null === $this->vendor ? $this->path : $this->vendor . '/' . $this->site;
    }
}
