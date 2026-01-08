<?php

use Castor\Attribute\AsTask;
use Spiral\Goridge;

use function Castor\io;

#[AsTask(default: true)]
function transform_docker_file(string $options): void
{
    $options = json_decode($options);
    $dockerfile = stream_get_contents(STDIN);

    // parse options build args
    $args = [];

    // create tcp connection to golang rpc server
    $socket = fsockopen("127.0.0.1", 6001, $errno, $errstr, 30);

    if (!$socket) {
        echo "Error: $errstr ($errno)\n";
        exit(1);
    }

    stream_set_timeout($socket, 1);

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

    $loader = new RpcTwigLoader($socket, clean_dockerfile($dockerfile));
    $twig = new \Twig\Environment($loader, [
        'debug' => true,
    ]);
    $twig->addFunction(new \Twig\TwigFunction('copy', function (string $source, string $target) use ($twig, $args) {
        $content = $twig->render($source, $args);

        echo "COPY <<EOF {$target}\n";
        echo $content;
        echo "\nEOF\n";
    }));
    $twig->addExtension(new \Twig\Extension\DebugExtension());

    echo $twig->render('.', $args);
    echo "\n";
}

function clean_dockerfile(string $value): string
{
    // remove all first lines starting with '#'
    $lines = explode("\n", $value);

    $cleanedLines = array_filter($lines, function ($line) {
        return !str_starts_with(trim($line), '#');
    });

    return implode("\n", $cleanedLines);
}

class RpcTwigLoader implements \Twig\Loader\LoaderInterface
{
    private array $templates = [];

    public function __construct(
        private $socket,
        string $content
    )
    {
        $this->templates['.'] = new \Twig\Source($content, ".");
    }

    public function getSourceContext(string $name): \Twig\Source
    {
        $source = $this->loadTemplate($name);

        if ($source !== null) {
            return $source;
        }

        throw new \Twig\Error\LoaderError(sprintf('Template "%s" is not defined.', $name));
    }

    public function exists(string $name): bool
    {
        return $this->loadTemplate($name) !== null;
    }

    public function getCacheKey(string $name): string
    {
        return $name;
    }

    public function isFresh(string $name, int $time): bool
    {
        return true;
    }

    private function loadTemplate(string $name): ?\Twig\Source
    {
        if (isset($this->templates[$name])) {
            return $this->templates[$name];
        }

        $context = "context";
        $loadName = $name;

        if (str_starts_with($name, "@")) {
            $parts = explode("/", $name, 2);
            $context = substr($parts[0], 1); // remove @
            $loadName = $parts[1] ?? "";
        }

        $content = $this->getFileFromServer($context, $loadName);

        if ($content === "") {
            return null;
        }

        $this->templates[$name] = new \Twig\Source($content, $name);

        return $this->templates[$name];
    }

    private function getFileFromServer(string $context, string $filename): string
    {
        $message = json_encode([
            'context' => $context,
            'filename' => $filename,
        ]);

        // pack message length + message
        $length = strlen($message);
        $packed = pack('N', $length) . $message;

        // send to socket
        $written = fwrite($this->socket, $packed);

        // read response length
        $lengthData = fread($this->socket, 4);

        if (!$lengthData) {
            return "";
        }

        $responseLength = unpack('N', $lengthData)[1];

        return fread($this->socket, $responseLength);
    }
}