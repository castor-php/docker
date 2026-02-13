<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose;
use function Castor\Docker\docker_compose_run;
use function Castor\context;
use function Castor\watch;

final class GoService implements ServiceInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $version,
        private readonly string $directory = '.',
        /** @var string[] */
        private array $domains = [],
        private bool $allowHttpAccess = false,
        private string $sharedHomeDirectory = '.home',
    ) {}

    public function addDomain(string $domain): self
    {
        $this->domains[] = $domain;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $userId = $context->data['user_id'] ?? 1000;
        $projectName = $context->data['project_name'] ?? 'app';

        $appService = $builder
            ->service($this->name)
                ->image('golang:' . $this->version)
                ->user("{$userId}:{$userId}")
                ->volume($this->directory, '/app', 'cached')
                ->profile('default')
                ->workingDir('/app')
                ->command('/app/' . $this->name)
                ->volume($this->sharedHomeDirectory, '/home/app', 'cached')
                ->environment('HOME', '/home/app')
        ;

        if ($this->domains) {
            $appService
                ->withTraefikRouting("{$projectName}-{$this->name}", $this->domains, 80, $this->allowHttpAccess);
        }

        return $builder;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('build', $this->name, 'Build the ' . $this->name . ' application'),
            'function' => function (): void {
                docker_compose_run('go build -o ' . $this->name, service: $this->name);
            },
        ];

        yield [
            'task' => new AsTask('restart', $this->name, 'Restart the ' . $this->name . ' service'),
            'function' => function (): void {
                docker_compose(['restart', $this->name]);
            },
        ];

        yield [
            'task' => new AsTask('watch', $this->name, 'Watch for changes and rebuild then restart the ' . $this->name . ' application'),
            'function' => function (): void {
                $watchDirectory = str_starts_with($this->directory, '/') ? $this->directory : context()['root_dir'] . '/' . $this->directory;

                watch($watchDirectory, function ($file, $event): void {
                    if (!str_ends_with($file, '.go')) {
                        return;
                    }

                    docker_compose_run('go build -o ' . $this->name, service: $this->name);
                    docker_compose(['restart', $this->name]);
                });
            },
        ];
    }
}
