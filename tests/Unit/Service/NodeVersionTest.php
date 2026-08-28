<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\PHPService;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Tests\SnapshotTestCase;

/**
 * Node is installed in the builder stage from the NodeSource repository, which
 * publishes one repository per major version.
 */
final class NodeVersionTest extends SnapshotTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function build(PHPService $app): array
    {
        return $app->updateCompose($this->fixedContext(), new ComposeBuilder())->toArray();
    }

    private function nodeVersionOf(PHPService $app): ?string
    {
        return $this->build($app)['services']['app-builder']['build']['args']['node_version'] ?? null;
    }

    public function testTheVersionReachesTheStageInstallingNode(): void
    {
        static::assertSame('22.x', $this->nodeVersionOf(
            (new PHPService('app'))->withNodeVersion('22'),
        ));
    }

    /**
     * NodeSource names its repositories after the major only, so everything
     * naming the same major has to reach it the same way.
     */
    public function testOnlyTheMajorIsKept(): void
    {
        foreach (['22', '22.x', 'v22', 'v22.11.0', '22.11.0'] as $given) {
            static::assertSame('22.x', (new PHPService('app'))->withNodeVersion($given)->getNodeVersion(), $given);
        }
    }

    public function testAVersionNamingNoMajorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PHPService('app'))->withNodeVersion('lts/jod');
    }

    /**
     * The version is a Twig variable now, so the Dockerfile holds no default of
     * its own to drift from — but it does have to read the one we send.
     */
    public function testTheDockerfileReadsTheVariableWeSend(): void
    {
        $dockerfile = file_get_contents(\dirname(__DIR__, 3) . '/src/Resources/php/Dockerfile');

        static::assertStringContainsString('node_{{ node_version }}', $dockerfile);
        static::assertStringNotContainsString('NODEJS_VERSION', $dockerfile, 'The old build argument is still there.');
    }

    /**
     * The application container does not install node — passing it the version
     * would only invalidate its build cache for nothing.
     */
    public function testTheApplicationStageIsNotGivenTheVersion(): void
    {
        $compose = $this->build((new PHPService('app'))->withNodeVersion('22'));

        static::assertArrayNotHasKey('node_version', $compose['services']['app']['build']['args']);
    }
}
