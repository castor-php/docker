<?php

namespace Castor\Docker\Service;

use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Castor\Context;

use function Castor\Docker\docker_compose_run;
use function Castor\Docker\docker_exit_code;
use function Castor\PHPQa\twig_cs_fixer;
use function Castor\with;

class SymfonyService extends PHPService
{
    private string $twigCsFixerVersion = '*';

    public function withPhpTwigCsFixerVersion(string $version): self
    {
        $this->twigCsFixerVersion = $version;
        return $this;
    }

    public function updateCompose(Context $context, array $compose): array
    {
        return parent::updateCompose($context, $compose);
    }

    public function getTasks(): iterable
    {
        yield from parent::getTasks();

        yield [
            'task' => new AsTask('cache-clear', $this->name, 'Clears the application cache'),
            'function' => function (bool $warm = true) {
                docker_compose_run('rm -rf var/cache', $this->name . '-builder');

                if ($warm) {
                    docker_compose_run('php bin/console cache:warm', $this->name . '-builder');
                }
            },
        ];

        yield [
            'task' => new AsTask('cache-warmup', $this->name, 'Warms the application cache'),
            'function' => function () {
                docker_compose_run('php bin/console cache:warm', $this->name . '-builder');
            },
        ];

        yield [
            'task' => new AsTask('fixtures', $this->name . ':db', 'Loads fixtures'),
            'function' => function () {
                docker_compose_run('php bin/console doctrine:fixture:load', $this->name . '-builder');
            },
        ];

        yield [
            'task' => new AsTask('migrate', $this->name . ':db', 'Migrates database schema'),
            'function' => function () {
                docker_compose_run('php bin/console doctrine:database:create --if-not-exists', $this->name . '-builder');
                docker_compose_run('php bin/console doctrine:migration:migrate -n --allow-no-migration --all-or-nothing', $this->name . '-builder');
            },
        ];

        yield [
            'task' => new AsTask('symfony', $this->name, 'Run a Symfony console command'),
            'function' => function (#[AsRawTokens] array $args) {
                docker_exit_code('php bin/console ' . implode(' ', array_map(fn ($val) => '"' . $val . '"', $args)), $this->name . '-builder');
            },
        ];

        yield [
            'task' => new AsTask('twig-cs', $this->name . ':qa', 'Fixes Twig Coding Style'),
            'function' => function (bool $dryRun = false) {
                return with(function () use ($dryRun) {
                    return twig_cs_fixer(array_filter([
                        $dryRun ? null : '--fix',
                    ], fn ($val) => null !== $val), $this->twigCsFixerVersion);
                }, workingDirectory: $this->directory);
            },
        ];
    }
}
