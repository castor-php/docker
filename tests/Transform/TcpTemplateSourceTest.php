<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Transform;

use Castor\TwigDockerfile\TcpTemplateSource;
use PHPUnit\Framework\TestCase;

/**
 * Tests the wire protocol against a fake peer built with stream_socket_pair(),
 * no network involved.
 */
final class TcpTemplateSourceTest extends TestCase
{
    /** @var resource */
    private $client;

    /** @var resource */
    private $server;

    protected function setUp(): void
    {
        [$this->client, $this->server] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
    }

    protected function tearDown(): void
    {
        foreach ([$this->client, $this->server] as $socket) {
            if (\is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    public function testSendsWellFormedRequest(): void
    {
        $payload = 'FROM alpine:3';
        fwrite($this->server, pack('N', \strlen($payload)) . $payload);

        $source = new TcpTemplateSource($this->client);

        self::assertSame($payload, $source->load('base', 'Dockerfile'));

        $length = unpack('N', fread($this->server, 4))[1];
        $request = json_decode(fread($this->server, $length), true);

        self::assertSame(['context' => 'base', 'filename' => 'Dockerfile'], $request);
    }

    public function testReassemblesResponsesLargerThanAStreamChunk(): void
    {
        // stream reads return at most one chunk (~8KB): a single fread() would
        // silently truncate any template larger than that
        $payload = str_repeat("RUN echo padding\n", 8_000);
        fwrite($this->server, pack('N', \strlen($payload)) . $payload);

        $source = new TcpTemplateSource($this->client);

        self::assertSame($payload, $source->load('context', 'Dockerfile'));
    }

    public function testZeroLengthResponseMeansNotFound(): void
    {
        fwrite($this->server, pack('N', 0));

        $source = new TcpTemplateSource($this->client);

        self::assertNull($source->load('context', 'missing'));
    }

    public function testTimeoutWithoutResponseMeansNotFound(): void
    {
        // the Go frontend never answers when it cannot load the file
        stream_set_timeout($this->client, 0, 50_000);

        $source = new TcpTemplateSource($this->client);

        self::assertNull($source->load('context', 'missing'));
    }

    public function testTruncatedResponseHeaderMeansNotFound(): void
    {
        fwrite($this->server, "\x00\x00");
        stream_set_timeout($this->client, 0, 50_000);

        $source = new TcpTemplateSource($this->client);

        self::assertNull($source->load('context', 'missing'));
    }
}
