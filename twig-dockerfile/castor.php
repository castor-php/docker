<?php

use Castor\Attribute\AsTask;
use Castor\TwigDockerfile\DockerfileTransformer;
use Castor\TwigDockerfile\TcpTemplateSource;

require_once __DIR__ . '/src/TemplateSourceInterface.php';
require_once __DIR__ . '/src/ArrayTemplateSource.php';
require_once __DIR__ . '/src/TcpTemplateSource.php';
require_once __DIR__ . '/src/TemplateSourceLoader.php';
require_once __DIR__ . '/src/DockerfileTransformer.php';

#[AsTask(default: true)]
function transform_docker_file(string $options): void
{
    $options = json_decode($options, true);
    $dockerfile = stream_get_contents(STDIN);

    // connect to the golang rpc server, used to load templates from build contexts
    $socket = fsockopen('127.0.0.1', 6001, $errno, $errstr, 30);

    if (!$socket) {
        fwrite(STDERR, "Error: cannot connect to the frontend server: $errstr ($errno)\n");
        exit(1);
    }

    stream_set_timeout($socket, 10);

    $transformer = new DockerfileTransformer(new TcpTemplateSource($socket));

    echo $transformer->transform($dockerfile, DockerfileTransformer::buildArgsFromOptions($options ?? []));
    echo "\n";
}
