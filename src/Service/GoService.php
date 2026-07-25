<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasDirectory;
use Castor\Docker\Service\Behaviour\HasHttpRouting;
use Castor\Docker\Service\Behaviour\HasSharedHomeDirectory;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose;
use function Castor\Docker\docker_compose_run;
use function Castor\context;
use function Castor\watch;

final class GoService implements ServiceInterface
{
    use HasDirectory;
    use HasHttpRouting;
    use HasSharedHomeDirectory;
    use HasVersion;

    public function __construct(
        private readonly string $name,
    ) {}

    protected function getDefaultVersion(): string
    {
        return '1';
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $userId = $context->data['user_id'] ?? 1000;

        $appService = $builder
            ->service($this->name)
                ->image('golang:' . $this->getVersion())
                ->user("{$userId}:{$userId}")
                ->volume($this->getDirectory(), '/app', 'cached')
                ->profile('default')
                ->workingDir('/app')
                ->command('/app/' . $this->name)
                ->volume($this->getSharedHomeDirectory(), '/home/app', 'cached')
                ->environment('HOME', '/home/app')
        ;

        $this->applyHttpRouting($appService);

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
                $watchDirectory = str_starts_with($this->getDirectory(), '/') ? $this->getDirectory() : context()['root_dir'] . '/' . $this->getDirectory();

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
