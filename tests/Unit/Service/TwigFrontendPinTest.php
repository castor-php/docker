<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\Builder\BuildBuilder;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\ClickhouseService;
use Castor\Docker\Service\GoBuilder;
use Castor\Docker\Service\GoService;
use Castor\Docker\Service\PhpMode;
use Castor\Docker\Service\PHPService;
use Castor\Docker\Service\RabbitMQService;
use Castor\Docker\Service\RustBuilder;
use Castor\Docker\Service\RustService;
use Castor\Docker\Service\ServiceInterface;
use Castor\Docker\Tests\SnapshotTestCase;

/**
 * The Dockerfiles shipped by the plugin are Twig templates and carry no
 * "# syntax=" line: that line is text outside the blocks, which costs a
 * template the ability to extend another one and, in turn, to be extended.
 *
 * What pins the frontend is the BUILDKIT_SYNTAX build argument, which BuildKit
 * honours over the directive. It has to be on every service building one of
 * those templates, or BuildKit reads a Dockerfile full of Twig tags as a
 * Dockerfile.
 */
final class TwigFrontendPinTest extends SnapshotTestCase
{
    /**
     * @return list<ServiceInterface>
     */
    private function services(): array
    {
        return [
            (new PHPService('app'))->withDirectory('/project/app')->addWorker('messenger', 'php bin/console messenger:consume'),
            (new PHPService('legacy'))->withMode(PhpMode::Fpm)->withDirectory('/project/legacy'),
            (new ClickhouseService())->withVersion('25.8'),
            (new GoService('api'))->withVersion('1.25')->withDirectory('/project/api'),
            (new RustService('agent'))->withVersion('1.90')->withDirectory('/project/agent'),
            (new GoBuilder('go-builder'))->withVersion('1.25')->withDirectory('/project')->withApp('server/exporter'),
            (new RustBuilder('rust-builder'))->withVersion('1.90')->withDirectory('/project')->withApp('server/injector'),
            // Builds a plain Dockerfile: the argument is none of its business.
            new RabbitMQService(),
        ];
    }

    public function testEveryServiceBuildingATemplatePinsTheFrontend(): void
    {
        $checked = 0;

        foreach ($this->buildsOfEveryService() as $name => $build) {
            if (!str_contains($this->dockerfileOf($build), '{%')) {
                static::assertArrayNotHasKey('BUILDKIT_SYNTAX', $build['args'] ?? [], \sprintf(
                    'The Dockerfile of "%s" is not a template, pinning a renderer for it says something that is not true.',
                    $name,
                ));

                continue;
            }

            ++$checked;

            static::assertSame(
                BuildBuilder::TWIG_DOCKERFILE_FRONTEND,
                $build['args']['BUILDKIT_SYNTAX'] ?? null,
                \sprintf('"%s" builds a Twig template without pinning the frontend that renders it.', $name),
            );
        }

        static::assertGreaterThan(5, $checked, 'The templates stopped being reached: the check no longer proves anything.');
    }

    /**
     * @return iterable<string, array{context: string, dockerfile?: string, args?: array<string, string>}>
     */
    private function buildsOfEveryService(): iterable
    {
        $builder = new ComposeBuilder();

        foreach ($this->services() as $service) {
            $builder = $service->updateCompose($this->fixedContext(), $builder);
        }

        /** @var array{services: array<string, array{build?: array{context: string, dockerfile?: string, args?: array<string, string>}}>} $compose */
        $compose = $builder->toArray();

        foreach ($compose['services'] as $name => $service) {
            if (isset($service['build'])) {
                yield $name => $service['build'];
            }
        }
    }

    /**
     * The file as it is on disk: a name in the compose that stops resolving is
     * a broken build, and a check reading nothing would pass quietly.
     *
     * @param array{context: string, dockerfile?: string} $build
     */
    private function dockerfileOf(array $build): string
    {
        $dockerfile = $build['dockerfile'] ?? 'Dockerfile';

        if (!str_starts_with($dockerfile, '/')) {
            $dockerfile = $build['context'] . '/' . $dockerfile;
        }

        static::assertFileExists($dockerfile);
        $source = file_get_contents($dockerfile);
        \assert($source !== false);

        return $source;
    }
}
