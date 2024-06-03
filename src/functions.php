<?php

namespace Castor\Docker;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Exception\ExceptionInterface;

use function Castor\context;
use function Castor\io;
use function Castor\variable;

#[AsTask(description: 'Builds the infrastructure', aliases: ['build'], namespace: 'docker')]
function build(
    ?string $service = null,
    ?string $profile = null,
): void {
    io()->title('Building infrastructure');

    $command = [];

    if ($profile) {
        $command[] = '--profile';
        $command[] = $profile;
    }

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

    docker_compose($command);
}

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Builds and starts the infrastructure', aliases: ['up'], namespace: 'docker')]
function up(
    ?string $service = null,
    #[AsOption(mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
): void {
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
    $command = ['logs', '-f', '--tail', '150'];

    if ($service) {
        $command[] = $service;
    }

    docker_compose($command, c: context()->withTty(), profiles: $profiles);
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
//
//#[AsContext(default: true)]
//function create_default_context(): Context
//{
//    $data = [
//        'project_name' => 'app',
//        'root_domain' => 'app.test',
//        'extra_domains' => [],
//        'project_directory' => 'application',
//        'php_version' => '8.2',
//        'docker_compose_files' => [
//            'docker-compose.yml',
//            'docker-compose.worker.yml',
//        ],
//        'macos' => false,
//        'power_shell' => false,
//        // check if posix_geteuid is available, if not, use getmyuid (windows)
//        'user_id' => \function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(),
//        'root_dir' => \dirname(__DIR__),
//    ];
//
//    if (file_exists($data['root_dir'] . '/infrastructure/docker/docker-compose.override.yml')) {
//        $data['docker_compose_files'][] = 'docker-compose.override.yml';
//    }
//
//    // We need an empty context to run command, since the default context has
//    // not been set in castor, since we ARE creating it right now
//    $emptyContext = new Context();
//
//    $data['composer_cache_dir'] = cache('composer_cache_dir', function () use ($emptyContext): string {
//        $composerCacheDir = capture(['composer', 'global', 'config', 'cache-dir', '-q'], onFailure: '', context: $emptyContext);
//        // If PHP is broken, the output will not be a valid path but an error message
//        if (!is_dir($composerCacheDir)) {
//            $composerCacheDir = sys_get_temp_dir() . '/castor/composer';
//            // If the directory does not exist, we create it. Otherwise, docker
//            // will do, as root, and the user will not be able to write in it.
//            if (!is_dir($composerCacheDir)) {
//                mkdir($composerCacheDir, 0o777, true);
//            }
//        }
//
//        return $composerCacheDir;
//    });
//
//    $platform = strtolower(php_uname('s'));
//    if (str_contains($platform, 'darwin')) {
//        $data['macos'] = true;
//        $data['docker_compose_files'][] = 'docker-compose.docker-for-x.yml';
//    } elseif (\in_array($platform, ['win32', 'win64', 'windows nt'])) {
//        $data['docker_compose_files'][] = 'docker-compose.docker-for-x.yml';
//        $data['power_shell'] = true;
//    }
//
//    if ($data['user_id'] > 256000) {
//        $data['user_id'] = 1000;
//    }
//
//    if (0 === $data['user_id']) {
//        log('Running as root? Fallback to fake user id.', 'warning');
//        $data['user_id'] = 1000;
//    }
//
//    return new Context(
//        $data,
//        pty: Process::isPtySupported(),
//        environment: [
//            'BUILDKIT_PROGRESS' => 'plain',
//        ]
//    );
//}
