<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Tests;

use Frootbox\Workbench\Build\BuildRun;
use Frootbox\Workbench\Build\BuildRuns;
use Frootbox\Workbench\Extension\CentralPackages;
use Frootbox\Workbench\Extension\WorkbenchExtension;
use Frootbox\Workbench\Extension\WorkbenchSource;
use Frootbox\Workbench\Project\Project;
use Frootbox\Workbench\Workbench;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The build from the backend (decision 0291), with a command in place of
 * the workbench.
 */
#[CoversClass(BuildRuns::class)]
#[CoversClass(BuildRun::class)]
final class BuildRunsTest extends TestCase
{
    private string $root;

    private Workbench $workbench;

    private Project $project;

    private string $builds;

    protected function setUp(): void
    {
        $this->root = Folder::create();
        Folder::write($this->root . '/workbench.yaml', "domain: fb.localhost\nroots: [.]\n");
        Folder::project($this->root . '/Acme/Demo');
        $this->workbench = Workbench::fromRoot($this->root);
        $this->project = $this->workbench->projects->get('demo') ?? throw new RuntimeException('No project');
        $this->builds = $this->workbench->config->var('builds');
    }

    protected function tearDown(): void
    {
        Folder::remove($this->root);
    }

    public function testWithoutARunItSuggestsTheNewestBuild(): void
    {
        foreach (['demo-acme-20261104-091723', 'demo-acme-20261106-101003', 'demo-acme-20261106-101003-since-20261104-091723', 'other-acme-20261107-000000'] as $name) {
            mkdir($this->builds . '/' . $name, 0o777, true);
        }

        $run = new BuildRuns($this->workbench, $this->project)->show();

        self::assertSame(BuildRun::NONE, $run->state);
        self::assertSame('demo-acme-20261106-101003', $run->suggestion);
        self::assertSame('demo-acme-latest', $run->latest);
    }

    public function testBuildingAgainNeverMovesTheBaseUntilTheUpload(): void
    {
        mkdir($this->builds . '/demo-acme-20261104-091723', 0o777, true);
        $runs = new BuildRuns($this->workbench, $this->project, ['/bin/sh', '-c', 'echo "Built: /b/demo-acme-20261106-101003"']);

        $runs->start('demo-acme-20261104-091723');
        $this->finished($runs);
        // The new build and the diff of the last one name the new build.
        mkdir($this->builds . '/demo-acme-20261106-101003', 0o777, true);
        Folder::write($this->builds . '/demo-acme-latest/BUILD', "demo-acme-20261106-101003\n");

        $run = $runs->show();
        self::assertSame('demo-acme-20261104-091723', $run->suggestion, 'Not uploaded yet: the base stays.');
        self::assertNull($run->server);

        $marked = $runs->markUploaded('demo-acme-20261106-101003');

        self::assertSame('demo-acme-20261106-101003', $marked->server);
        self::assertSame('demo-acme-20261106-101003', $marked->suggestion);
        self::assertSame('demo-acme-20261106-101003', new BuildRuns($this->workbench, $this->project)->suggestion(), 'The note wins over the base of the last run.');
    }

    public function testOnlyABuildOfTheProjectCanBeUploaded(): void
    {
        $this->expectException(RuntimeException::class);

        new BuildRuns($this->workbench, $this->project)->markUploaded('demo-acme-latest');
    }

    public function testRunsInTheBackgroundAndReadsTheResult(): void
    {
        mkdir($this->builds . '/demo-acme-20261104-091723', 0o777, true);
        $script = 'sleep 1; echo "Built: /b/demo-acme-20261106-101003"; echo "Diff:  /b/demo-acme-latest (12 files, only what changed)"; echo "Gone:  /b/demo-acme-latest.deleted.txt (2 files, may be deleted on the server)"';
        $runs = new BuildRuns($this->workbench, $this->project, ['/bin/sh', '-c', $script]);

        $started = $runs->start('demo-acme-20261104-091723');

        self::assertSame(BuildRun::RUNNING, $started->state);
        self::assertSame('demo-acme-20261104-091723', $started->since);
        self::assertTrue($runs->running());

        $run = $this->finished($runs);

        self::assertSame(BuildRun::DONE, $run->state);
        self::assertSame('demo-acme-20261106-101003', $run->build);
        self::assertSame('demo-acme-latest', $run->diff);
        self::assertSame(12, $run->files);
        self::assertSame(2, $run->gone);
        self::assertSame(0, $run->exit);
        self::assertNotContains('[exit 0]', $run->output);
        self::assertFalse($runs->running());
    }

    public function testAFailedBuildKeepsItsOutput(): void
    {
        $runs = new BuildRuns($this->workbench, $this->project, ['/bin/sh', '-c', 'echo "Composer failed, see above."; exit 1']);
        $runs->start(null);

        $run = $this->finished($runs);

        self::assertSame(BuildRun::FAILED, $run->state);
        self::assertSame(1, $run->exit);
        self::assertNull($run->since);
        self::assertSame(['Composer failed, see above.'], $run->output);
    }

    public function testRefusesABuildThatIsNoneOfTheProject(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is no build of Acme/Demo');

        new BuildRuns($this->workbench, $this->project, ['/bin/true'])->start('other-acme-20261107-000000');
    }

    public function testEveryWebsiteOfTheWorkbenchKnowsWhereItRuns(): void
    {
        $environment = $this->workbench->environment($this->project, ['PATH' => '/usr/bin']);

        self::assertSame($this->root, $environment[BuildRuns::ROOT]);
        self::assertSame('demo.acme', $environment[BuildRuns::PROJECT]);
    }

    public function testOnlyTheWorkbenchAddsItselfToTheExtensions(): void
    {
        $central = new CentralPackages($this->root . '/vendor');
        $names = static fn(WorkbenchSource $source): array => array_map(static fn($package): string => $package->name, $source->packages());

        self::assertContains(WorkbenchExtension::PACKAGE, $names($this->workbench->source($this->project)));
        self::assertNotContains(WorkbenchExtension::PACKAGE, $names(new WorkbenchSource($central, $this->project->path)));
    }

    private function finished(BuildRuns $runs): BuildRun
    {
        for ($i = 0; $i < 100; ++$i) {
            $run = $runs->show();

            if (BuildRun::RUNNING !== $run->state) {
                return $run;
            }

            usleep(100_000);
        }

        self::fail('The build did not finish.');
    }
}
