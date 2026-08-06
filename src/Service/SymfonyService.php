<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_exit_code;
use function Castor\PHPQa\twig_cs_fixer;
use function Castor\with;

class SymfonyService extends PHPService
{
    private string $twigCsFixerVersion = '*';

    public function withPhpTwigCsFixerVersion(string $version): static
    {
        $this->twigCsFixerVersion = $version;
        return $this;
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        return parent::updateCompose($context, $builder);
    }

    public function getTasks(): iterable
    {
        yield from parent::getTasks();

        yield [
            'task' => new AsTask('cache-clear', $this->name, 'Clears the application cache'),
            'function' => function (bool $warm = true): void {
                $this->runInBuilder('rm -rf var/cache');

                if ($warm) {
                    $this->runInBuilder('php bin/console cache:warm');
                }
            },
        ];

        yield [
            'task' => new AsTask('cache-warmup', $this->name, 'Warms the application cache'),
            'function' => function (): void {
                $this->runInBuilder('php bin/console cache:warm');
            },
        ];

        yield [
            'task' => new AsTask('fixtures', $this->name . ':db', 'Loads fixtures'),
            'function' => function (): void {
                $this->runInBuilder('php bin/console doctrine:fixture:load');
            },
        ];

        yield [
            'task' => new AsTask('migrate', $this->name . ':db', 'Migrates database schema'),
            'function' => function (): void {
                $this->runInBuilder('php bin/console doctrine:database:create --if-not-exists');
                $this->runInBuilder('php bin/console doctrine:migration:migrate -n --allow-no-migration --all-or-nothing');
            },
        ];

        yield [
            'task' => new AsTask('symfony', $this->name, 'Run a Symfony console command'),
            'function' => function (#[AsRawTokens] array $args): void {
                docker_exit_code(
                    'php bin/console ' . implode(' ', array_map(fn($val) => '"' . $val . '"', $args)),
                    $this->getBuilderServiceName(),
                    workDir: $this->getBuilderWorkingDirectory(),
                );
            },
        ];

        yield [
            'task' => new AsTask('twig-cs', $this->name . ':qa', 'Fixes Twig Coding Style'),
            'function' => fn(bool $dryRun = false) => with(fn() => twig_cs_fixer(array_filter([
                $dryRun ? null : '--fix',
            ], fn($val) => null !== $val), $this->twigCsFixerVersion), workingDirectory: $this->getHostWorkingDirectory()),
        ];
    }
}
