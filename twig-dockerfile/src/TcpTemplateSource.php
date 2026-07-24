<?php

declare(strict_types=1);

namespace Castor\TwigDockerfile;

/**
 * Loads template files from the Go frontend over a length-prefixed framing
 * protocol: 4 bytes (big-endian length) followed by the payload, in both
 * directions. The request payload is a JSON object {context, filename}.
 */
final class TcpTemplateSource implements TemplateSourceInterface
{
    /**
     * @param resource $socket
     */
    public function __construct(
        private $socket,
    ) {}

    public function load(string $context, string $filename): ?string
    {
        $message = json_encode(['context' => $context, 'filename' => $filename], \JSON_THROW_ON_ERROR);

        $this->writeAll(pack('N', \strlen($message)) . $message);

        $header = $this->readExact(4);

        if ($header === null) {
            // The frontend does not answer when the file cannot be loaded, so a
            // timeout or a closed connection means "not found".
            return null;
        }

        /** @var int $length */
        $length = unpack('N', $header)[1];

        if ($length === 0) {
            return null;
        }

        return $this->readExact($length);
    }

    private function writeAll(string $data): void
    {
        $total = \strlen($data);
        $offset = 0;

        while ($offset < $total) {
            $written = fwrite($this->socket, substr($data, $offset));

            if ($written === false || $written === 0) {
                throw new \RuntimeException('Failed to write to the frontend socket.');
            }

            $offset += $written;
        }
    }

    private function readExact(int $length): ?string
    {
        $buffer = '';

        while (\strlen($buffer) < $length) {
            $chunk = fread($this->socket, $length - \strlen($buffer));

            if ($chunk === false || $chunk === '') {
                return null;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
