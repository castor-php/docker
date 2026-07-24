<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\E2e;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Full end-to-end check of the BuildKit frontend: builds the twig-dockerfile
 * image, then runs a real `docker build` whose syntax line points at it.
 *
 * Requires Docker with the default (docker) buildx driver so the locally
 * built frontend image is resolvable. Opt in with RUN_E2E=1; set E2E_IMAGE to
 * reuse an already-built frontend image instead of building one.
 */
#[Group('e2e')]
final class TwigDockerfileBuildTest extends TestCase
{
    public function testDockerBuildWithTwigSyntax(): void
    {
        if (!getenv('RUN_E2E')) {
            static::markTestSkipped('Set RUN_E2E=1 to run the end-to-end docker build test.');
        }

        $root = \dirname(__DIR__, 2);
        $image = getenv('E2E_IMAGE');

        if (!$image) {
            $image = 'twig-dockerfile-e2e:local';
            $this->runOk(['docker', 'build', '-t', $image, $root . '/twig-dockerfile'], timeout: 1800);
        }

        $buildDir = sys_get_temp_dir() . '/twig-dockerfile-e2e-' . getmypid();
        mkdir($buildDir, 0o777, true);

        try {
            file_put_contents($buildDir . '/Dockerfile', <<<DOCKERFILE
                # syntax={$image}
                {% extends 'base.Dockerfile' %}

                {% block message %}hello-from-twig-{{ greeting }}{% endblock %}
                DOCKERFILE);

            file_put_contents($buildDir . '/base.Dockerfile', <<<'DOCKERFILE'
                FROM alpine:3
                RUN echo "{% block message %}default{% endblock %}" > /message.txt
                CMD ["cat", "/message.txt"]
                DOCKERFILE);

            $tag = 'twig-dockerfile-e2e-result:local';
            $this->runOk(
                ['docker', 'build', '--build-arg', 'greeting=world', '-t', $tag, $buildDir],
                timeout: 600,
                env: ['DOCKER_BUILDKIT' => '1'],
            );

            $run = $this->runOk(['docker', 'run', '--rm', $tag]);

            static::assertStringContainsString('hello-from-twig-world', $run->getOutput());
        } finally {
            array_map('unlink', glob($buildDir . '/*'));
            rmdir($buildDir);
        }
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    private function runOk(array $command, int $timeout = 120, array $env = []): Process
    {
        $process = new Process($command, timeout: $timeout, env: $env);
        $process->run();

        static::assertTrue($process->isSuccessful(), \sprintf(
            "Command \"%s\" failed:\n%s\n%s",
            implode(' ', $command),
            $process->getOutput(),
            $process->getErrorOutput(),
        ));

        return $process;
    }
}
