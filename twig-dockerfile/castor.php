<?php

use Castor\Attribute\AsTask;

use function Castor\io;

#[AsTask(default: true)]
function transform_docker_file(string $options): void
{
    $options = json_decode($options);
    $dockerfile = stream_get_contents(STDIN);

    echo $dockerfile;
    echo "\n";
    echo "RUN echo 'Options: " . json_encode($options) . "';\n";
}