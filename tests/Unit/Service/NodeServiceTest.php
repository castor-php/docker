<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\NodeService;
use Castor\Docker\Service\PackageManager;
use Castor\Docker\Tests\SnapshotTestCase;

/**
 * The compose configuration a Node service generates — the snapshot freezes its
 * shape, this checks the decisions behind it.
 */
final class NodeServiceTest extends SnapshotTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function definition(NodeService $service): array
    {
        $compose = $service->updateCompose($this->fixedContext(), new ComposeBuilder())->toArray();

        return $compose['services'][$service->getName()];
    }

    public function testTheContainerRunsTheDevScriptOfTheChosenManager(): void
    {
        static::assertSame(['npm', 'run', 'dev'], $this->definition(new NodeService('front'))['command']);

        static::assertSame(
            ['pnpm', 'run', 'dev'],
            $this->definition((new NodeService('front'))->withPackageManager(PackageManager::Pnpm))['command'],
        );

        static::assertSame(
            ['yarn', 'run', 'start'],
            $this->definition((new NodeService('front'))->withPackageManager(PackageManager::Yarn)->withScript('start'))['command'],
        );
    }

    /**
     * An application that is not started by a package.json script at all. A
     * list is the argument vector, a string goes through a shell — which is
     * exactly what compose does with each form.
     */
    public function testTheRunCommandReplacesTheScript(): void
    {
        static::assertSame(
            ['node', 'server.js'],
            $this->definition((new NodeService('front'))->withRunCommand(['node', 'server.js']))['command'],
        );

        static::assertSame(
            'npm run dev -- --host 0.0.0.0',
            $this->definition((new NodeService('front'))->withRunCommand('npm run dev -- --host 0.0.0.0'))['command'],
        );
    }

    /**
     * Next.js, Nuxt and react-scripts read PORT, so the port the router
     * forwards to and the port the dev server binds are one setting.
     */
    public function testThePortIsAnnouncedToTheDevServerAndToTheRouter(): void
    {
        $definition = $this->definition(
            (new NodeService('front'))->withPort(5173)->withDomain('front.demo.test')
        );

        static::assertSame('5173', $definition['environment']['PORT']);
        static::assertSame('0.0.0.0', $definition['environment']['HOST']);
        static::assertContains('caddy.reverse_proxy={{upstreams 5173}}', $definition['labels']);
    }

    /**
     * Polling costs CPU on every service that does not need it, so it is opted
     * into — but a bind mount on Docker Desktop or on a Windows filesystem
     * carries no inotify event, and nothing reloads without it.
     */
    public function testPollingIsOffUntilAskedFor(): void
    {
        static::assertArrayNotHasKey('CHOKIDAR_USEPOLLING', $this->definition(new NodeService('front'))['environment']);

        $environment = $this->definition((new NodeService('front'))->withPolling())['environment'];

        static::assertSame('true', $environment['CHOKIDAR_USEPOLLING']);
        static::assertSame('true', $environment['WATCHPACK_POLLING']);
    }

    /**
     * The defaults this service sets are defaults: a project needing another
     * value for one of them has withEnvironment() and nothing else to learn.
     */
    public function testTheProjectEnvironmentWinsOverTheDefaults(): void
    {
        $definition = $this->definition(
            (new NodeService('front'))->withPort(3000)->withEnvironment('PORT', '4000')
        );

        static::assertSame('4000', $definition['environment']['PORT']);
    }

    /**
     * A monorepo mounts its root and names the package below it, the way every
     * other application service does.
     */
    public function testTheWorkingDirectoryFollowsThePackage(): void
    {
        $definition = $this->definition(
            (new NodeService('front'))->withDirectory('/project')->withWorkingDirectory('apps/front')
        );

        static::assertSame('/app/apps/front', $definition['working_dir']);
        static::assertContains('/project:/app:cached', $definition['volumes']);
    }

    /**
     * The manager corepack downloads on first use is worth keeping: in the
     * shared home directory it is fetched once for the whole project, and
     * survives a rebuild of the image.
     */
    public function testCorepackCachesInTheSharedHomeDirectory(): void
    {
        $definition = $this->definition(new NodeService('front'));

        static::assertSame('/home/app/.cache/node/corepack', $definition['environment']['COREPACK_HOME']);
        static::assertContains('.home:/home/app:cached', $definition['volumes']);
    }
}
