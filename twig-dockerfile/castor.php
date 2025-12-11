<?php

use Castor\Attribute\AsTask;

use function Castor\io;

#[AsTask(default: true)]
function transform_docker_file(string $options): void
{
    $options = json_decode($options);
    $dockerfile = stream_get_contents(STDIN);

    // parse options build args
    $args = [];

    foreach ($options as $key => $value) {
        // can be like 'build-arg:KEY' => 'value'
        if (str_starts_with($key, 'build-arg:')) {
            $argKey = substr($key, strlen('build-arg:'));

            // try to json decode value
            try {
                $decodedValue = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                $value = $decodedValue;
            } catch (JsonException $e) {
                // not json, keep original value
            }

            $args[$argKey] = $value;
        }
    }

    $loader = new \Twig\Loader\ArrayLoader([
        'Dockerfile' => $dockerfile,
    ]);
    $twig = new \Twig\Environment($loader);


    echo $twig->render('Dockerfile', $args);
    echo "\n";

    foreach ($options as $key => $value) {
        dump_dockerfile("Build arg: " . $key . "==" . $value);
    }

    dump_dockerfile("Current pwd: " . getcwd());

    // list files in current directory recursively
    $finder = \Castor\finder()->in("/mnt");

    foreach ($finder->files() as $file) {
        dump_dockerfile("File: " . $file->getPath());
    }
}

function dump_dockerfile(string $value): void
{
    echo "RUN echo '" . $value . "'\n";
}