<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\BinaryRunService;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\GoService;
use Castor\Docker\Service\PHPService;
use Castor\Docker\Service\RustService;
use Castor\Docker\Service\ServiceInterface;
use Castor\Docker\Tests\SnapshotTestCase;

/**
 * Go, Rust and binary services read their options the same way, whichever
 * language they run.
 */
final class SourceServiceTest extends SnapshotTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function definition(ServiceInterface $service): array
    {
        $compose = $service->updateCompose($this->fixedContext(), new ComposeBuilder())->toArray();

        return $compose['services'][$service->getName()];
    }

    /**
     * A list goes after the binary, a string is the whole command.
     */
    public function testTheRunCommandAppendsToTheBinaryOrReplacesIt(): void
    {
        static::assertSame('/app/api', $this->definition(new GoService('api'))['command']);
        static::assertSame(['/app/api', '--verbose'], $this->definition((new GoService('api'))->withRunCommand(['--verbose']))['command']);
        static::assertSame('sh -c "./api serve"', $this->definition((new GoService('api'))->withRunCommand('sh -c "./api serve"'))['command']);

        static::assertSame(['/app/target/debug/api', '--verbose'], $this->definition((new RustService('api'))->withRunCommand(['--verbose']))['command']);

        static::assertSame(['/app/bin/api', '--verbose'], $this->definition((new BinaryRunService('api', 'bin/api'))->withRunCommand(['--verbose']))['command']);
        static::assertSame('/app/bin/api serve', $this->definition((new BinaryRunService('api', 'bin/api'))->withRunCommand('/app/bin/api serve'))['command']);
    }

    /**
     * The project gets the last word over the variables the service sets.
     */
    public function testTheProjectSetsAnyVariable(): void
    {
        static::assertSame('debug', $this->definition((new GoService('api'))->withEnvironment('LOG_LEVEL', 'debug'))['environment']['LOG_LEVEL']);
        static::assertSame('/cargo', $this->definition((new RustService('api'))->withEnvironment('CARGO_HOME', '/cargo'))['environment']['CARGO_HOME']);
    }

    /**
     * A PHP application gets them in every container: its builder and its
     * workers run the same code.
     */
    public function testAPhpApplicationGetsTheVariablesEverywhere(): void
    {
        $app = (new PHPService('app'))->withEnvironment('LOG_LEVEL', 'debug')->addWorker('consume', 'php bin/console messenger:consume');
        $compose = $app->updateCompose($this->fixedContext(), new ComposeBuilder())->toArray();

        foreach (['app', 'app-builder', 'app-worker-consume'] as $container) {
            static::assertSame('debug', $compose['services'][$container]['environment']['LOG_LEVEL'] ?? null, $container);
        }
    }

    public function testAGoApplicationListensOn8080(): void
    {
        static::assertSame(8080, (new GoService('api'))->getPort());
    }
}
