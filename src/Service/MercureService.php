<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;
use Castor\Docker\Service\Behaviour\HasName;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\worktree_domain;

/**
 * A Mercure hub. link() it to an application to hand it MERCURE_URL,
 * MERCURE_PUBLIC_URL and MERCURE_JWT_SECRET, the variables of the
 * symfony/mercure-bundle recipe.
 *
 * Where the hub runs is decided by who links to it: a lone FrankenPHP
 * application with a domain serves it from its own Caddyfile, since FrankenPHP
 * is Caddy with the Mercure module compiled in. Anything else gets a container
 * of its own, on "{name}.{root_domain}".
 *
 * Pinned to the 0.x series, which the FrankenPHP images embed and
 * symfony/mercure-bundle speaks by default: Mercure 1.0 changed the protocol
 * and rejects what a default Symfony application sends.
 */
class MercureService implements LinkAwareServiceInterface
{
    use HasName;
    use HasVersion;

    /**
     * The one of the symfony/mercure-bundle recipe, so an application still
     * carrying it in its .env matches the hub. At least the 32 bytes
     * lcobucci/jwt requires for HS256.
     */
    public const DEFAULT_JWT_SECRET = '!ChangeThisMercureHubJWTSecretKey!';

    protected string $jwtSecret = self::DEFAULT_JWT_SECRET;

    /** @var list<string> */
    protected array $corsOrigins = [];

    /** @var list<ServiceInterface> */
    protected array $linkedServices = [];

    protected function getDefaultVersion(): string
    {
        return 'v0.24.2';
    }

    protected function getDefaultName(): string
    {
        return 'mercure';
    }

    /**
     * The key both publishers and subscribers sign their tokens with.
     */
    public function withJwtSecret(string $jwtSecret): static
    {
        $this->jwtSecret = $jwtSecret;

        return $this;
    }

    public function getJwtSecret(): string
    {
        return $this->jwtSecret;
    }

    /**
     * Allow origins other than the ones of the linked applications — a
     * front-end of another stack, or an application behind the redirection.io
     * agent, which holds its domain. In a worktree, an origin under the root
     * domain moves with it.
     */
    public function withCorsOrigin(string ...$origins): static
    {
        foreach ($origins as $origin) {
            $origin = rtrim($origin, '/');

            if (!\in_array($origin, $this->corsOrigins, true)) {
                $this->corsOrigins[] = $origin;
            }
        }

        return $this;
    }

    public function linkedFrom(ServiceInterface $service): void
    {
        if (!\in_array($service, $this->linkedServices, true)) {
            $this->linkedServices[] = $service;
        }
    }

    /**
     * The application serving the hub itself: the only service linked to it,
     * when that one is a FrankenPHP application with a domain.
     */
    public function getHost(): ?PHPService
    {
        if (1 !== \count($this->linkedServices)) {
            return null;
        }

        $service = $this->linkedServices[0];

        if (!$service instanceof PHPService || PhpMode::FrankenPhp !== $service->getMode() || !$service->getDomains()) {
            return null;
        }

        return $service;
    }

    /**
     * Plain HTTP on the project network: the containers do not trust the
     * certificates of the router.
     */
    public function getUrl(): string
    {
        return 'http://' . ($this->getHost()?->getName() ?? $this->getName()) . '/.well-known/mercure';
    }

    /**
     * Where a browser subscribes, through the router: the first domain of the
     * application serving the hub, or the domain of the container.
     */
    public function getPublicUrl(Context $context): string
    {
        $host = $this->getHost();
        $domain = null === $host ? $this->getDomain($context) : worktree_domain($host->getDomains()[0], $context);

        return 'https://' . $domain . '/.well-known/mercure';
    }

    public function getDomain(Context $context): string
    {
        return $this->getName() . '.' . ($context->data['root_domain'] ?? 'castor.local');
    }

    /**
     * Every domain of the linked applications, plus the ones given to
     * withCorsOrigin(). An explicit list rather than "*": the hub answers "*"
     * without the credentials a browser needs to send the authorization cookie
     * of private updates.
     *
     * @return list<string>
     */
    public function getCorsOrigins(Context $context): array
    {
        $origins = [];

        foreach ($this->linkedServices as $service) {
            if (!method_exists($service, 'getDomains')) {
                continue;
            }

            $httpAccess = method_exists($service, 'isHttpAccessAllowed') && $service->isHttpAccessAllowed();

            /** @var string $domain */
            foreach ($service->getDomains() as $domain) {
                // Its pages will be served on the moved domain.
                $domain = worktree_domain($domain, $context);

                $origins[] = 'https://' . $domain;

                if ($httpAccess) {
                    $origins[] = 'http://' . $domain;
                }
            }
        }

        foreach ($this->corsOrigins as $origin) {
            // Moved like a domain of an application would be.
            $origins[] = preg_replace_callback(
                '#^(https?://)([^/:]+)#',
                static fn(array $matches): string => $matches[1] . worktree_domain($matches[2], $context),
                $origin,
            ) ?? $origin;
        }

        return array_values(array_unique($origins));
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        // Served by the application, see PHPService::updateCompose().
        if (null !== $this->getHost()) {
            return $builder;
        }

        $name = $this->getName();

        $directives = [];

        if ($origins = $this->getCorsOrigins($context)) {
            $directives[] = 'cors_origins ' . implode(' ', $origins);
        }

        // A development hub: anonymous subscribers, subscription API, debug UI.
        $directives[] = 'anonymous';
        $directives[] = 'subscriptions';
        $directives[] = 'ui';

        return $builder
            ->volume($name . '-data')
            ->service($name)
                ->image('dunglas/mercure:' . $this->getVersion())
                // Plain HTTP: the router terminates TLS in front of it.
                ->environment('SERVER_NAME', ':80')
                ->environment('MERCURE_PUBLISHER_JWT_KEY', $this->jwtSecret)
                ->environment('MERCURE_SUBSCRIBER_JWT_KEY', $this->jwtSecret)
                ->environment('MERCURE_EXTRA_DIRECTIVES', implode("\n", $directives))
                // The admin API logs every request, the healthcheck included:
                // one line every five seconds.
                ->environment('GLOBAL_OPTIONS', "log default {\n\texclude admin.api\n}")
                // The Bolt database keeping the history a reconnecting
                // subscriber catches up with.
                ->volume($name . '-data', '/data')
                // busybox wget tries ::1 first, where the admin API does not
                // listen.
                ->healthcheck(['CMD', 'wget', '-q', '--spider', 'http://127.0.0.1:2019/mercure/health/ready'])
                ->withHttpRouting($this->getDomain($context), 80)
                ->profile('default')
            ->end()
        ;
    }

    public function getLinkEnvironment(Context $context): array
    {
        return [
            'MERCURE_URL' => $this->getUrl(),
            'MERCURE_PUBLIC_URL' => $this->getPublicUrl($context),
            'MERCURE_JWT_SECRET' => $this->jwtSecret,
        ];
    }

    /**
     * Started is enough: an application boots without publishing anything.
     * Nothing to wait for when the application serves the hub itself.
     */
    public function getLinkDependencies(): array
    {
        if (null !== $this->getHost()) {
            return [];
        }

        return [$this->getName() => 'service_started'];
    }

    public function getTasks(): iterable
    {
        return [];
    }
}
