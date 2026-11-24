<?php

declare(strict_types=1);

namespace Castor\Docker;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Console\Output\VerbosityLevel;
use Castor\Context;
use Symfony\Component\Console\Helper\TableSeparator;

use function Castor\context;
use function Castor\io;

/**
 * Parse a size the way the docker CLI writes it — "1.26GB", "254.1MiB", "0B".
 *
 * Docker prints disk sizes in SI units and memory in binary ones, hence both
 * scales.
 */
function parse_docker_size(string $size): ?int
{
    if (!preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*([a-zA-Z]*)\s*$/', $size, $matches)) {
        return null;
    }

    $units = [
        '' => 1,
        'b' => 1,
        'kb' => 1000,
        'mb' => 1000 ** 2,
        'gb' => 1000 ** 3,
        'tb' => 1000 ** 4,
        'pb' => 1000 ** 5,
        'kib' => 1024,
        'mib' => 1024 ** 2,
        'gib' => 1024 ** 3,
        'tib' => 1024 ** 4,
        'pib' => 1024 ** 5,
    ];

    $unit = strtolower($matches[2]);

    if (!isset($units[$unit])) {
        return null;
    }

    return (int) round((float) $matches[1] * $units[$unit]);
}

/**
 * Parse a percentage as the docker CLI writes it — "99.27%", or "--".
 */
function parse_docker_percent(string $percent): ?float
{
    if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*%\s*$/', $percent, $matches)) {
        return null;
    }

    return (float) $matches[1];
}

/**
 * Render a byte count the way docker renders one.
 */
function format_bytes(int|float $bytes): string
{
    $units = ['B', 'kB', 'MB', 'GB', 'TB', 'PB'];
    $value = (float) $bytes;
    $unit = 0;

    while (abs($value) >= 1000 && $unit < \count($units) - 1) {
        $value /= 1000;
        ++$unit;
    }

    return \sprintf('%.4g%s', $value, $units[$unit]);
}

/**
 * Parse the "key=value,key=value" label list the docker CLI prints.
 *
 * @return array<string, string>
 */
function parse_docker_labels(string $labels): array
{
    $parsed = [];

    foreach (explode(',', $labels) as $label) {
        $pair = explode('=', $label, 2);

        if (2 === \count($pair) && '' !== $pair[0]) {
            $parsed[$pair[0]] = $pair[1];
        }
    }

    return $parsed;
}

/**
 * Every container compose created for this project, running or not.
 *
 * $withSize makes docker walk the filesystem of each container to measure its
 * writable layer, so only the disk usage task asks for it.
 *
 * @return list<array{id: string, service: string, oneOff: bool, state: string, status: string, size: ?int, image: string}>
 */
function get_project_containers(bool $withSize = false, ?Context $c = null): array
{
    $c ??= context();

    // Read field by field rather than as JSON: the "Labels" of the JSON output
    // is a flat "key=value,key=value" string, ambiguous for a label holding a
    // comma — com.docker.compose.project.config_files does.
    $format = implode("\t", [
        '{{.ID}}',
        '{{.Label "com.docker.compose.service"}}',
        '{{.Label "com.docker.compose.oneoff"}}',
        '{{.State}}',
        '{{.Status}}',
        '{{.Size}}',
        '{{.Image}}',
    ]);

    $command = [
        'docker', 'ps',
        '--all',
        '--filter', 'label=com.docker.compose.project=' . get_project_name($c),
        '--format', $format,
    ];

    if ($withSize) {
        $command[] = '--size';
    }

    $output = quiet_capture($command, $c->withTimeout(null));

    $containers = [];

    foreach (explode("\n", $output) as $line) {
        $fields = explode("\t", trim($line));

        if (7 !== \count($fields) || '' === $fields[0]) {
            continue;
        }

        [$id, $service, $oneOff, $state, $status, $size, $image] = $fields;

        $containers[] = [
            'id' => $id,
            'service' => '' !== $service ? $service : $image,
            'oneOff' => 'True' === $oneOff,
            'state' => $state,
            'status' => $status,
            // "49.2kB (virtual 1.36GB)": only the first number is the
            // container's, the rest is the image it shares with its siblings.
            'size' => parse_docker_size(explode(' ', $size)[0]),
            'image' => $image,
        ];
    }

    usort($containers, fn(array $a, array $b) => [$a['service'], $a['id']] <=> [$b['service'], $b['id']]);

    return $containers;
}

/**
 * What the given containers are consuming right now, keyed by container id.
 *
 * @param list<string> $ids only running containers: docker has nothing to
 *                          sample on a stopped one
 *
 * @return array<string, array{cpu: ?float, memory: ?int, memoryLimit: ?int, memoryPercent: ?float, netIn: ?int, netOut: ?int, blockIn: ?int, blockOut: ?int, pids: ?int}>
 */
function get_container_stats(array $ids, ?Context $c = null): array
{
    // "docker stats" with no argument samples every container of the daemon.
    if (!$ids) {
        return [];
    }

    $c ??= context();

    $output = quiet_capture(['docker', 'stats', '--no-stream', '--format', '{{json .}}', ...$ids], $c->withTimeout(null));

    $stats = [];

    foreach (explode("\n", $output) as $line) {
        $sample = json_decode(trim($line), true);

        if (!\is_array($sample) || !\is_string($sample['ID'] ?? null)) {
            continue;
        }

        $memory = array_map(trim(...), explode('/', $sample['MemUsage'] ?? ''));
        $network = array_map(trim(...), explode('/', $sample['NetIO'] ?? ''));
        $block = array_map(trim(...), explode('/', $sample['BlockIO'] ?? ''));

        $stats[$sample['ID']] = [
            'cpu' => parse_docker_percent($sample['CPUPerc'] ?? ''),
            'memory' => parse_docker_size($memory[0]),
            'memoryLimit' => parse_docker_size($memory[1] ?? ''),
            'memoryPercent' => parse_docker_percent($sample['MemPerc'] ?? ''),
            'netIn' => parse_docker_size($network[0]),
            'netOut' => parse_docker_size($network[1] ?? ''),
            'blockIn' => parse_docker_size($block[0]),
            'blockOut' => parse_docker_size($block[1] ?? ''),
            'pids' => is_numeric($sample['PIDs'] ?? null) ? (int) $sample['PIDs'] : null,
        ];
    }

    return $stats;
}

/**
 * The images and volumes of this project, and the disk they take.
 *
 * "docker system df" is the only place docker reports the size of a volume or
 * the layers an image shares, and it reports them for the whole daemon.
 *
 * Images carry no compose label, so they are recognised by name: the
 * "<project>-<service>" this plugin builds, and the image every project
 * container was started from.
 *
 * @return array{images: list<array{name: string, size: ?int, exclusive: ?int, containers: int}>, volumes: list<array{name: string, size: ?int, links: int}>}
 */
function get_project_disk_usage(?Context $c = null): array
{
    $c ??= context();
    $empty = ['images' => [], 'volumes' => []];

    // Measuring every volume of the daemon takes a while, so no timeout.
    $output = quiet_capture(['docker', 'system', 'df', '--verbose', '--format', '{{json .}}'], $c->withTimeout(null));

    $usage = json_decode($output, true);

    if (!\is_array($usage)) {
        return $empty;
    }

    $project = get_project_name($c);

    // Not matched on the id: "docker system df" reports the digest of the image
    // index, a container that of the image configuration.
    $imageNames = [$project => true];

    foreach (get_compose_service_names($c) as $service) {
        $imageNames[$project . '-' . $service] = true;
    }

    foreach (get_project_containers(c: $c) as $container) {
        // A container whose image lost its tag names it by id, which matches
        // nothing here.
        if ('' !== $container['image'] && !preg_match('/^[0-9a-f]{12,64}$/', $container['image'])) {
            $imageNames[$container['image']] = true;
        }
    }

    $images = [];

    foreach ($usage['Images'] ?? [] as $image) {
        $repository = $image['Repository'] ?? '';
        $tag = $image['Tag'] ?? '';

        // The plugin builds untagged images, docker tags what it pulls.
        if (!isset($imageNames[$repository]) && !isset($imageNames[$repository . ':' . $tag])) {
            continue;
        }

        $images[] = [
            'name' => '' === $tag || '<none>' === $tag ? $repository : $repository . ':' . $tag,
            'size' => parse_docker_size($image['Size'] ?? ''),
            'exclusive' => parse_docker_size($image['UniqueSize'] ?? ''),
            'containers' => (int) ($image['Containers'] ?? 0),
        ];
    }

    $volumes = [];

    foreach ($usage['Volumes'] ?? [] as $volume) {
        $labels = parse_docker_labels($volume['Labels'] ?? '');

        if (($labels['com.docker.compose.project'] ?? null) !== $project) {
            continue;
        }

        $volumes[] = [
            'name' => $volume['Name'] ?? '',
            'size' => parse_docker_size($volume['Size'] ?? ''),
            'links' => (int) ($volume['Links'] ?? 0),
        ];
    }

    usort($images, fn(array $a, array $b) => $a['name'] <=> $b['name']);
    usort($volumes, fn(array $a, array $b) => $a['name'] <=> $b['name']);

    return ['images' => $images, 'volumes' => $volumes];
}

/**
 * @return array{cpus: ?int, memory: ?int}
 */
function get_docker_host_resources(?Context $c = null): array
{
    $c ??= context();

    $output = quiet_capture(['docker', 'info', '--format', "{{.NCPU}}\t{{.MemTotal}}"], $c);

    $fields = explode("\t", $output);

    return [
        'cpus' => is_numeric($fields[0]) ? (int) $fields[0] : null,
        'memory' => is_numeric($fields[1] ?? null) ? (int) $fields[1] : null,
    ];
}

#[AsTask(description: 'Show the CPU, memory and disk the project uses', aliases: ['stats'], namespace: 'docker')]
function stats(
    #[AsOption(description: 'Skip the disk usage, which makes docker measure every image, container and volume')]
    bool $noDisk = false,
): void {
    $c = context();
    $detailed = $c->verbosityLevel->value > VerbosityLevel::NORMAL->value;

    io()->title(\sprintf('Stats for "%s"', get_project_name($c)));

    $containers = get_project_containers(withSize: !$noDisk, c: $c);
    $running = array_values(array_filter($containers, fn(array $container) => 'running' === $container['state']));
    $samples = get_container_stats(array_column($running, 'id'), $c);

    io()->section(\sprintf('Containers (%d running out of %d)', \count($running), \count($containers)));

    if (!$containers) {
        io()->text('This project has no container. Start it with <comment>castor ' . task_name('Castor\Docker\up') . '</comment>.');
    } else {
        $rows = [];
        $totals = ['cpu' => 0.0, 'memory' => 0, 'netIn' => 0, 'netOut' => 0, 'blockIn' => 0, 'blockOut' => 0, 'pids' => 0];

        foreach ($containers as $container) {
            $sample = $samples[$container['id']] ?? null;

            foreach ($totals as $key => $total) {
                $totals[$key] = $total + ($sample[$key] ?? 0);
            }

            $rows[] = [
                $container['service'] . ($container['oneOff'] ? ' <fg=gray>(one-off)</>' : ''),
                'running' === $container['state']
                    ? '<fg=green>' . $container['status'] . '</>'
                    : '<fg=yellow>' . $container['status'] . '</>',
                null === ($sample['cpu'] ?? null) ? '-' : \sprintf('%.2f%%', $sample['cpu']),
                null === ($sample['memory'] ?? null) ? '-' : format_bytes($sample['memory']),
                null === ($sample['memoryPercent'] ?? null) ? '-' : \sprintf('%.2f%%', $sample['memoryPercent']),
                null === ($sample['netIn'] ?? null) ? '-' : format_bytes($sample['netIn']) . ' / ' . format_bytes($sample['netOut'] ?? 0),
                null === ($sample['blockIn'] ?? null) ? '-' : format_bytes($sample['blockIn']) . ' / ' . format_bytes($sample['blockOut'] ?? 0),
                $sample['pids'] ?? '-',
            ];
        }

        $rows[] = new TableSeparator();
        $rows[] = [
            '<info>Total</>',
            '',
            \sprintf('<info>%.2f%%</>', $totals['cpu']),
            '<info>' . format_bytes($totals['memory']) . '</>',
            '',
            format_bytes($totals['netIn']) . ' / ' . format_bytes($totals['netOut']),
            format_bytes($totals['blockIn']) . ' / ' . format_bytes($totals['blockOut']),
            (string) $totals['pids'],
        ];

        io()->table(['Service', 'Status', 'CPU', 'Memory', 'Mem %', 'Net I/O', 'Block I/O', 'PIDs'], $rows);

        $host = get_docker_host_resources($c);
        $proportions = [];

        if (null !== $host['cpus']) {
            $proportions[] = \sprintf('%.2f of its %d cores', $totals['cpu'] / 100, $host['cpus']);
        }

        if (null !== $host['memory']) {
            $proportions[] = \sprintf(
                '%s of its %s of memory (%.2f%%)',
                format_bytes($totals['memory']),
                format_bytes($host['memory']),
                $host['memory'] > 0 ? $totals['memory'] / $host['memory'] * 100 : 0,
            );
        }

        if ($proportions) {
            io()->text('Right now this project takes ' . implode(' and ', $proportions) . ' from this machine.');
        }
    }

    if ($noDisk) {
        return;
    }

    io()->section('Disk usage');

    $usage = get_project_disk_usage($c);

    $imageSize = array_sum(array_column($usage['images'], 'size'));
    $imageExclusive = array_sum(array_column($usage['images'], 'exclusive'));
    $containerSize = array_sum(array_column($containers, 'size'));
    $volumeSize = array_sum(array_column($usage['volumes'], 'size'));

    io()->table(
        ['What', 'Count', 'Size', 'Exclusive'],
        [
            ['Images', \count($usage['images']), format_bytes($imageSize), format_bytes($imageExclusive)],
            ['Containers', \count($containers), format_bytes($containerSize), format_bytes($containerSize)],
            ['Volumes', \count($usage['volumes']), format_bytes($volumeSize), format_bytes($volumeSize)],
            new TableSeparator(),
            [
                '<info>Total</>',
                '',
                '<info>' . format_bytes($imageSize + $containerSize + $volumeSize) . '</>',
                '<info>' . format_bytes($imageExclusive + $containerSize + $volumeSize) . '</>',
            ],
        ],
    );

    io()->text('"Exclusive" is what destroying the project would actually free: layers its images share with images of other projects only count in "Size".');

    if (!$detailed) {
        io()->comment('Run with <comment>-v</comment> to list every image and volume.');

        return;
    }

    if ($usage['images']) {
        io()->table(
            ['Image', 'Size', 'Exclusive', 'Containers'],
            array_map(
                fn(array $image) => [$image['name'], format_bytes($image['size'] ?? 0), format_bytes($image['exclusive'] ?? 0), $image['containers']],
                $usage['images'],
            ),
        );
    }

    if ($usage['volumes']) {
        io()->table(
            ['Volume', 'Size', 'Used by'],
            array_map(
                fn(array $volume) => [
                    $volume['name'],
                    format_bytes($volume['size'] ?? 0),
                    0 === $volume['links'] ? '<fg=yellow>nothing</>' : \sprintf('%d container(s)', $volume['links']),
                ],
                $usage['volumes'],
            ),
        );
    }
}
