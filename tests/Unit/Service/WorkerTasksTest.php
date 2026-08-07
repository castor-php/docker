<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\PHPService;
use PHPUnit\Framework\TestCase;

/**
 * The worker tasks act on one worker or on all of them, so the name given on
 * the command line has to resolve to the right containers — and a name that
 * resolves to nothing has to say so rather than run "docker compose stop" with
 * no argument, which would stop the whole project.
 */
final class WorkerTasksTest extends TestCase
{
    private function app(string ...$workers): PHPService
    {
        $app = new class ('app') extends PHPService {
            /**
             * @return list<string>
             */
            public function workerServices(?string $worker = null): array
            {
                return $this->resolveWorkerServices($worker);
            }
        };

        foreach ($workers as $worker) {
            $app->addWorker($worker, 'php bin/console ' . $worker);
        }

        return $app;
    }

    public function testNoWorkerNameTargetsThemAll(): void
    {
        $app = $this->app('messenger', 'scheduler');

        static::assertSame(
            ['app-worker-messenger', 'app-worker-scheduler'],
            $app->workerServices(),
        );
    }

    public function testAWorkerNameTargetsOnlyThatOne(): void
    {
        $app = $this->app('messenger', 'scheduler');

        static::assertSame(['app-worker-scheduler'], $app->workerServices('scheduler'));
    }

    /**
     * Silently falling back to every worker — or worse, to no argument at all —
     * would stop containers the user did not name.
     */
    public function testAnUnknownWorkerIsRejected(): void
    {
        $app = $this->app('messenger');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has no worker named "typo"');

        $app->workerServices('typo');
    }

    public function testTheErrorListsTheDeclaredWorkers(): void
    {
        $app = $this->app('messenger', 'scheduler');

        $this->expectExceptionMessage('Declared: messenger, scheduler.');

        $app->workerServices('typo');
    }
}
