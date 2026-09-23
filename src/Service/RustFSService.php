<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasName;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\expose_service_port;

/**
 * An S3-compatible object storage, RustFS, with its web console.
 *
 * The API is served on "{name}.{root_domain}", the console on
 * "{name}-console.{root_domain}". The buckets declared with withBucket() are
 * created by a one-shot container once the server is ready, and the
 * applications linked to it wait for that container to succeed: they start
 * with their buckets there.
 *
 * link() it to an application to hand it the credentials, the endpoint and
 * the region under the names the AWS SDK for PHP and Laravel read — see
 * getLinkEnvironment().
 */
class RustFSService implements LinkableServiceInterface
{
    use HasName;
    use HasVersion;

    public const DEFAULT_ACCESS_KEY = 'castor';
    public const DEFAULT_SECRET_KEY = 'castor-secret-key';

    /** What RustFS answers as, and what every SDK accepts for a server of its own. */
    public const REGION = 'us-east-1';

    protected string $accessKey = self::DEFAULT_ACCESS_KEY;

    protected string $secretKey = self::DEFAULT_SECRET_KEY;

    /** @var array<string, bool> the buckets to create, and whether anonymous reads are allowed */
    protected array $buckets = [];

    protected string $clientVersion = 'v0.1.36';

    protected function getDefaultVersion(): string
    {
        return '1.0.0';
    }

    protected function getDefaultName(): string
    {
        return 'rustfs';
    }

    /**
     * The root credentials: the ones the console logs in with, and the ones
     * the linked applications receive.
     */
    public function withCredentials(string $accessKey, string $secretKey): static
    {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;

        return $this;
    }

    public function getAccessKey(): string
    {
        return $this->accessKey;
    }

    public function getSecretKey(): string
    {
        return $this->secretKey;
    }

    /**
     * A bucket to create when the stack starts. It is only ever created: a
     * bucket removed from the list stays, with its objects, until removed from
     * the console.
     *
     * A public bucket lets anyone download its objects without signing the
     * request — what an <img src> pointing at an uploaded file needs. Only the
     * permission is granted on every start: turning it off again is done from
     * the console, like any policy set there.
     */
    public function withBucket(string $name, bool $public = false): static
    {
        // The rules of S3, and what makes the name safe to hand to a shell.
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid bucket name: 3 to 63 lowercase letters, digits, dots and hyphens, starting and ending with a letter or a digit.', $name));
        }

        $this->buckets[$name] = $public;

        return $this;
    }

    /**
     * @return array<string, bool>
     */
    public function getBuckets(): array
    {
        return $this->buckets;
    }

    /**
     * The version of rc, the RustFS client, the buckets are created with.
     */
    public function withClientVersion(string $version): static
    {
        $this->clientVersion = $version;

        return $this;
    }

    /**
     * The one-shot container creating the buckets.
     */
    public function getBucketsServiceName(): string
    {
        return $this->getName() . '-buckets';
    }

    /**
     * The S3 endpoint of the other containers, over plain HTTP on the project
     * network: they do not trust the certificates of the router.
     */
    public function getEndpoint(): string
    {
        return 'http://' . $this->getName() . ':9000';
    }

    /**
     * The S3 endpoint of the browsers, through the router.
     */
    public function getPublicEndpoint(Context $context): string
    {
        return 'https://' . $this->getDomain($context);
    }

    public function getDomain(Context $context): string
    {
        return $this->getName() . '.' . ($context->data['root_domain'] ?? 'castor.local');
    }

    public function getConsoleDomain(Context $context): string
    {
        return $this->getName() . '-console.' . ($context->data['root_domain'] ?? 'castor.local');
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $name = $this->getName();

        $builder
            ->volume($name . '-data')
            ->service($name)
                ->image('rustfs/rustfs:' . $this->getVersion())
                ->environment('RUSTFS_ACCESS_KEY', $this->accessKey)
                ->environment('RUSTFS_SECRET_KEY', $this->secretKey)
                ->environment('RUSTFS_CONSOLE_ENABLE', 'true')
                // A browser uploading straight to a presigned URL comes from
                // the origin of the application. The request carries its own
                // signature, no cookie: any origin is as safe as another.
                ->environment('RUSTFS_CORS_ALLOWED_ORIGINS', '*')
                // The image logs to files in /logs, which leaves "docker:logs"
                // empty. An empty directory sends them to the standard output.
                ->environment('RUSTFS_OBS_LOG_DIRECTORY', '')
                // A named volume: the server runs as uid 10001 and refuses a
                // directory of the host it cannot write to.
                ->volume($name . '-data', '/data')
                ->healthcheck(['CMD', 'curl', '-fsS', '-o', '/dev/null', 'http://127.0.0.1:9000/health/ready'])
                // Two listeners, two sites: the S3 API, and the console, which
                // only answers on a port of its own.
                ->withHttpRouting($this->getDomain($context), 9000)
                ->withHttpRouting($this->getConsoleDomain($context), 9001)
                ->profile('default')
        ;

        if ($this->buckets) {
            $builder
                ->service($this->getBucketsServiceName())
                    ->image('rustfs/rc:' . $this->clientVersion)
                    ->environment('RC_HOST_local', \sprintf('http://%s:%s@%s:9000', rawurlencode($this->accessKey), rawurlencode($this->secretKey), $name))
                    ->entrypoint(['/bin/sh', '-ec'])
                    ->command([$this->getBucketsScript()])
                    ->dependsOn($name, ['condition' => 'service_healthy'])
                    // Done once it has run: nothing to bring back.
                    ->restart('no')
                    ->profile('default')
            ;
        }

        return $builder;
    }

    /**
     * Safe to run on every start: an existing bucket is left alone.
     */
    protected function getBucketsScript(): string
    {
        $lines = [];

        foreach ($this->buckets as $bucket => $public) {
            $lines[] = 'rc bucket create --ignore-existing local/' . $bucket;

            if ($public) {
                $lines[] = 'rc bucket anonymous set download local/' . $bucket;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The names each ecosystem reads, since none of them agree:
     *
     *  - AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY, read by all of them;
     *  - AWS_REGION for the AWS SDK and async-aws, AWS_DEFAULT_REGION for
     *    Laravel;
     *  - AWS_ENDPOINT_URL_S3, which the AWS SDK reads on its own, and
     *    AWS_ENDPOINT for Laravel. AWS_ENDPOINT_URL — the only one async-aws
     *    reads — is left out on purpose: it would send the SQS or SES clients
     *    of the application to the object storage too;
     *  - AWS_USE_PATH_STYLE_ENDPOINT for Laravel, which a Symfony
     *    configuration references too: no SDK reads one, and a virtual-hosted
     *    bucket would be looked up as "<bucket>.rustfs";
     *  - S3_PUBLIC_ENDPOINT, the endpoint the browsers reach, to presign the
     *    URLs they follow: a signature covers the host it was made for.
     */
    public function getLinkEnvironment(Context $context): array
    {
        return [
            'AWS_ACCESS_KEY_ID' => $this->accessKey,
            'AWS_SECRET_ACCESS_KEY' => $this->secretKey,
            'AWS_REGION' => self::REGION,
            'AWS_DEFAULT_REGION' => self::REGION,
            'AWS_ENDPOINT_URL_S3' => $this->getEndpoint(),
            'AWS_ENDPOINT' => $this->getEndpoint(),
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
            'S3_PUBLIC_ENDPOINT' => $this->getPublicEndpoint($context),
        ];
    }

    /**
     * With buckets, the application waits for them to exist; the one-shot
     * container itself waits for the server to be ready.
     */
    public function getLinkDependencies(): array
    {
        if ($this->buckets) {
            return [$this->getBucketsServiceName() => 'service_completed_successfully'];
        }

        return [$this->getName() => 'service_started'];
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('expose', $this->getName(), description: 'Expose the S3 API of the rustfs service over TCP on the host (--stop to stop)'),
            'function' => function (
                #[AsArgument(description: 'Host port to expose on (defaults to the service port)')]
                ?int $port = null,
                bool $stop = false,
            ): void {
                expose_service_port($this->getName(), 9000, $port, $stop);
            },
        ];
    }
}
