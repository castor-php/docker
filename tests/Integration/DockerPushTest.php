<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * "docker:push" hands the compose file to "docker buildx bake" and tells it,
 * per service, where to export the build cache. Nothing but a real registry
 * says whether that export actually happened, so this starts a throwaway one
 * and pushes to it.
 *
 * Runs on tests/fixtures/push-project, which declares the three cases the task
 * has to tell apart — a full "type=registry,ref=..." cache, the bare reference
 * compose also accepts, and a service that builds with no cache at all.
 *
 * Requires a castor binary (CASTOR_BINARY env var, or "castor" in PATH) and a
 * running Docker daemon.
 */
final class DockerPushTest extends TestCase
{
    // Outside tests/Integration on purpose: composer symlinks the plugin back
    // to the repository root, and PHPUnit would follow it while collecting
    // tests, walking the tree forever.
    private const PROJECT = __DIR__ . '/../fixtures/push-project';

    /**
     * bake's default group is every service that has a "build", cache or no
     * cache. The task names its targets to keep the others out — and if it
     * stopped doing so, the only symptom in production would be a push that
     * silently builds more than it was asked to.
     */
    public function testOnlyTheServicesWithACacheAreBuilt(): void
    {
        $castor = $this->castorOrSkip();

        $push = $this->castor($castor, ['docker:push', '--dry-run'], ['CASTOR_DOCKER_TEST_REGISTRY' => 'registry.invalid/ns']);

        static::assertTrue($push->isSuccessful(), "castor docker:push --dry-run failed:\n" . $push->getOutput() . $push->getErrorOutput());

        $plan = json_decode($push->getOutput(), true);

        static::assertIsArray($plan, "docker:push --dry-run did not print a bake plan:\n" . $push->getOutput());

        $targets = $plan['group']['default']['targets'];
        sort($targets);

        static::assertSame(['cached', 'shorthand'], $targets, '"uncached" builds but has no cache to push, it has no business being built here.');

        // The bare reference of "shorthand" is what compose accepts and buildx
        // does not: unspelled, this is where the whole push would fail.
        static::assertSame(
            [['mode' => 'max', 'ref' => 'registry.invalid/ns/shorthand:cache', 'type' => 'registry']],
            $plan['target']['shorthand']['cache-to'],
        );
    }

    public function testTheBuildCacheLandsInTheRegistry(): void
    {
        $castor = $this->castorOrSkip();

        if (!$this->docker(['info'])->isSuccessful()) {
            static::markTestSkipped('No running Docker daemon.');
        }

        $suffix = bin2hex(random_bytes(4));
        $network = "castor-docker-push-{$suffix}";
        $registry = "castor-docker-push-registry-{$suffix}";
        $builder = "castor-docker-push-builder-{$suffix}";
        $namespace = 'castor-docker-push';

        // The builder runs in its own container on CI, so it reaches the
        // registry by container name, on a network they both sit on — which is
        // the one address that works whatever driver buildx ends up using.
        $this->docker(['network', 'create', $network], check: true);

        try {
            $this->docker([
                'run', '--detach', '--rm',
                '--name', $registry,
                '--network', $network,
                '--publish', '127.0.0.1::5000',
                'registry:3',
            ], check: true);

            try {
                $published = trim($this->docker(['port', $registry, '5000/tcp'], check: true)->getOutput());
                $port = substr($published, strrpos($published, ':') + 1);
                $api = "http://127.0.0.1:{$port}/v2/";

                $this->waitFor($api);

                // Unlike the daemon, buildkit does not trust a plain HTTP
                // registry unless it is told to.
                $config = tempnam(sys_get_temp_dir(), 'buildkitd-');
                file_put_contents($config, "[registry.\"{$registry}:5000\"]\n  http = true\n");

                try {
                    $this->docker([
                        'buildx', 'create',
                        '--name', $builder,
                        '--driver', 'docker-container',
                        '--driver-opt', "network={$network}",
                        '--config', $config,
                    ], check: true);

                    $push = $this->castor($castor, ['docker:push'], [
                        'CASTOR_DOCKER_TEST_REGISTRY' => "{$registry}:5000/{$namespace}",
                        'BUILDX_BUILDER' => $builder,
                    ]);

                    static::assertTrue($push->isSuccessful(), "castor docker:push failed:\n" . $push->getOutput() . $push->getErrorOutput());
                } finally {
                    $this->docker(['buildx', 'rm', $builder]);
                    unlink($config);
                }

                foreach (['cached', 'shorthand'] as $service) {
                    $tags = json_decode((string) file_get_contents("{$api}{$namespace}/{$service}/tags/list"), true);

                    static::assertContains('cache', $tags['tags'] ?? [], "docker:push pushed no cache for \"{$service}\".");
                }
            } finally {
                $this->docker(['stop', $registry]);
            }
        } finally {
            $this->docker(['network', 'rm', $network]);
        }
    }

    private function castorOrSkip(): string
    {
        $castor = getenv('CASTOR_BINARY') ?: (new ExecutableFinder())->find('castor');

        if (!$castor) {
            static::markTestSkipped('No castor binary found: install castor or set CASTOR_BINARY.');
        }

        if (!(new ExecutableFinder())->find('docker')) {
            static::markTestSkipped('No docker binary found.');
        }

        if (!is_dir(self::PROJECT . '/.castor/vendor')) {
            $install = new Process([$castor, 'composer', 'install'], self::PROJECT, timeout: 300);
            $install->run();

            if (!$install->isSuccessful()) {
                static::markTestSkipped('Could not install the fixture dependencies: ' . $install->getErrorOutput());
            }
        }

        return $castor;
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     */
    private function castor(string $castor, array $command, array $environment): Process
    {
        $process = new Process([$castor, ...$command, '--no-interaction'], self::PROJECT, $environment, timeout: 600);
        $process->run();

        return $process;
    }

    /**
     * @param list<string> $command
     */
    private function docker(array $command, bool $check = false): Process
    {
        $process = new Process(['docker', ...$command], timeout: 300);
        $process->run();

        if ($check && !$process->isSuccessful()) {
            static::fail("{$process->getCommandLine()} failed:\n" . $process->getErrorOutput());
        }

        return $process;
    }

    private function waitFor(string $url): void
    {
        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);

        for ($i = 0; $i < 60; ++$i) {
            if (false !== @file_get_contents($url, context: $context)) {
                return;
            }

            usleep(500_000);
        }

        static::fail("The throwaway registry never answered on {$url}.");
    }
}
