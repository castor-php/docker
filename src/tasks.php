<?php

declare(strict_types=1);

namespace Castor\Docker;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function Castor\capture;
use function Castor\context;
use function Castor\exit_code;
use function Castor\io;
use function Castor\variable;
use function Castor\run;

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Builds the infrastructure', aliases: ['build'], namespace: 'docker')]
function build(
    ?string $service = null,
    #[AsOption(mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
): void {
    io()->title('Building infrastructure');

    $command = [];

    $buildArgs = variable('build_args', []);

    $command = [
        ...$command,
        'build',
    ];

    foreach ($buildArgs as $key => $value) {
        $command[] = '--build-arg';
        $command[] = "{$key}={$value}";
    }

    if ($service) {
        $command[] = $service;
    }

    if (!$profiles) {
        $profiles = get_default_profiles();
        $profiles[] = 'builder';
    }

    docker_compose($command, profiles: $profiles);
}

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Builds and starts the infrastructure', aliases: ['up'], namespace: 'docker')]
function up(
    ?string $service = null,
    #[AsOption(mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
    bool $build = false,
): void {
    if ($build) {
        build($service, $profiles);
    }

    if (!$service && !$profiles) {
        io()->title('Starting infrastructure');
    }

    $command = ['up', '--detach', '--no-build'];

    if ($service) {
        $command[] = $service;
    }

    try {
        docker_compose($command, profiles: $profiles);
    } catch (ExceptionInterface $e) {
        io()->error('An error occured while starting the infrastructure.');
        io()->note('Did you forget to run "castor docker:build"?');
        io()->note('Or you forget to login to the registry?');

        throw $e;
    }
}

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Stops the infrastructure', aliases: ['stop'], namespace: 'docker')]
function stop(
    ?string $service = null,
    #[AsOption(mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
): void {
    if (!$service || !$profiles) {
        io()->title('Stopping infrastructure');
    }

    $command = ['stop'];

    if ($service) {
        $command[] = $service;
    }

    docker_compose($command, profiles: $profiles);
}

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Displays infrastructure logs', aliases: ['logs'], namespace: 'docker')]
function logs(
    ?string $service = null,
    #[AsOption(mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
): void {
    $command = ['logs', '--tail', '150'];
    $c = context();

    if (Process::isTtySupported()) {
        $c = $c->withTty();
        $command[] = '-f';
    }

    if ($service) {
        $command[] = $service;
    }

    docker_compose($command, c: $c, profiles: $profiles);
}

#[AsTask(description: 'Lists containers status', aliases: ['ps'], namespace: 'docker')]
function ps(): void
{
    docker_compose(['ps']);
}

#[AsTask(description: 'Cleans the infrastructure (remove container, volume, networks)', aliases: ['destroy'], namespace: 'docker')]
function destroy(
    #[AsOption(description: 'Force the destruction without confirmation', shortcut: 'f')]
    bool $force = false,
): void {
    io()->title('Destroying infrastructure');

    if (!$force) {
        io()->warning('This will permanently remove all containers, volumes, networks... created for this project.');
        io()->note('You can use the --force option to avoid this confirmation.');
        if (!io()->confirm('Are you sure?', false)) {
            io()->comment('Aborted.');

            return;
        }
    }

    docker_compose(['down', '--remove-orphans', '--volumes', '--rmi=local']);
}

#[AsTask(description: 'Push images cache to the registry', namespace: 'docker', name: 'push', aliases: ['push'])]
function push(bool $dryRun = false): void
{
    $registry = variable('registry');

    if (!$registry) {
        throw new \RuntimeException('You must define a registry to push images.');
    }

    // Generate bake file
    $targets = [];

    foreach (get_services() as $service => $config) {
        $cacheFrom = $config['build']['cache_from'][0] ?? null;

        if (null === $cacheFrom) {
            continue;
        }

        $cacheFrom = explode(',', $cacheFrom);
        $reference = null;
        $type = null;

        if (1 === \count($cacheFrom)) {
            $reference = $cacheFrom[0];
            $type = 'registry';
        } else {
            foreach ($cacheFrom as $part) {
                $from = explode('=', $part);

                if (2 !== \count($from)) {
                    continue;
                }

                if ('type' === $from[0]) {
                    $type = $from[1];
                }

                if ('ref' === $from[0]) {
                    $reference = $from[1];
                }
            }
        }

        $targets[$service] = [
            'reference' => $reference,
            'type' => $type,
            'context' => $config['build']['context'],
            'dockerfile' => $config['build']['dockerfile'] ?? 'Dockerfile',
            'target' => $config['build']['target'] ?? null,
            'contexts' => $config['build']['additional_contexts'] ?? [],
            'args' => $config['build']['args'] ?? [],
        ];
    }

    $content = \sprintf(<<<'EOHCL'
        group "default" {
            targets = [%s]
        }

        EOHCL, implode(', ', array_map(fn($name) => \sprintf('"%s"', $name), array_keys($targets))));

    foreach ($targets as $service => $target) {
        $additionalContexts = "";
        $args = "";

        foreach ($target['contexts'] as $name => $path) {
            $additionalContexts .= \sprintf("%s = \"%s\"\n", $name, $path);
        }

        foreach ($target['args'] as $key => $value) {
            $args .= \sprintf("%s = \"%s\"\n", $key, $value);
        }

        $content .= \sprintf(<<<'EOHCL'
            target "%s" {
                context    = "%s"
                contexts   = {
                    %s
                }
                dockerfile = "%s"
                cache-from = ["%s"]
                cache-to   = ["type=%s,ref=%s,mode=max"]
                target     = "%s"
                args = {
                    %s
                }
            }

            EOHCL, $service, $target['context'], $additionalContexts, $target['dockerfile'], $target['reference'], $target['type'], $target['reference'], $target['target'], $args);
    }

    if ($dryRun) {
        io()->write($content);

        return;
    }

    // write bake file in tmp file
    $bakeFile = tempnam(sys_get_temp_dir(), 'bake');
    file_put_contents($bakeFile, $content);

    // Run bake
    run(['docker', 'buildx', 'bake', '-f', $bakeFile], context: context()->withEnvironment([
        'BUILDX_BAKE_ENTITLEMENTS_FS' => '0',
    ]));
}

/**
 * @return array<string, array{profiles?: list<string>, build: array{context: string, dockerfile?: string, cache_from?: list<string>, target?: string, additional_contexts?: array<string, string>, args?: array<string, string>}}>
 */
function get_services(): array
{
    return json_decode(
        docker_compose(
            ['config', '--format', 'json'],
            context()->withQuiet(),
            profiles: ['*'],
        )->getOutput(),
        true,
    )['services'];
}
