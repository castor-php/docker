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
 * Meilisearch, with its search preview dashboard.
 *
 * link() it to an application to hand it the URL and the key under the names
 * the ecosystem reads: MEILISEARCH_URL and MEILISEARCH_API_KEY for the Symfony
 * bundle, MEILISEARCH_HOST and MEILISEARCH_KEY for Laravel Scout, and
 * MEILISEARCH_PUBLIC_URL for a search running in the browser.
 */
class MeilisearchService implements LinkableServiceInterface
{
    use HasName;
    use HasVersion;

    /**
     * Long enough for the production mode too, which refuses a master key
     * shorter than 16 bytes.
     */
    public const DEFAULT_MASTER_KEY = 'castor-meilisearch-master-key';

    protected string $masterKey = self::DEFAULT_MASTER_KEY;

    /**
     * A minor, which Meilisearch publishes as a floating tag: patches come
     * with the next pull, and MEILI_UPGRADE_DB migrates the data they need.
     */
    protected function getDefaultVersion(): string
    {
        return 'v1.54';
    }

    protected function getDefaultName(): string
    {
        return 'meilisearch';
    }

    /**
     * The key the linked applications receive, and the one the dashboard asks
     * for.
     */
    public function withMasterKey(string $masterKey): static
    {
        $this->masterKey = $masterKey;

        return $this;
    }

    public function getMasterKey(): string
    {
        return $this->masterKey;
    }

    /**
     * The URL the other containers reach it on.
     */
    public function getUrl(): string
    {
        return 'http://' . $this->getName() . ':7700';
    }

    /**
     * The URL a browser reaches it on, through the router.
     */
    public function getPublicUrl(Context $context): string
    {
        return 'https://' . $this->getDomain($context);
    }

    public function getDomain(Context $context): string
    {
        return $this->getName() . '.' . ($context->data['root_domain'] ?? 'castor.local');
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $name = $this->getName();

        return $builder
            ->volume($name . '-data')
            ->service($name)
                ->image('getmeili/meilisearch:' . $this->getVersion())
                ->environment('MEILI_MASTER_KEY', $this->masterKey)
                // What serves the dashboard on the root URL.
                ->environment('MEILI_ENV', 'development')
                ->environment('MEILI_NO_ANALYTICS', 'true')
                // Meilisearch refuses a database written by another version,
                // even a patch apart, so the next pull of the floating tag
                // would leave it down until the volume is dropped.
                ->environment('MEILI_UPGRADE_DB', 'true')
                ->volume($name . '-data', '/meili_data')
                // It only listens on IPv4.
                ->healthcheck(['CMD', 'curl', '-fsS', '-o', '/dev/null', 'http://127.0.0.1:7700/health'])
                ->withHttpRouting($this->getDomain($context), 7700)
                ->profile('default')
            ->end()
        ;
    }

    public function getLinkEnvironment(Context $context): array
    {
        return [
            // meilisearch/search-bundle, and the Symfony AI store.
            'MEILISEARCH_URL' => $this->getUrl(),
            'MEILISEARCH_API_KEY' => $this->masterKey,
            // Laravel Scout.
            'MEILISEARCH_HOST' => $this->getUrl(),
            'MEILISEARCH_KEY' => $this->masterKey,
            // For a search running in the browser, which cannot reach the
            // project network.
            'MEILISEARCH_PUBLIC_URL' => $this->getPublicUrl($context),
        ];
    }

    /**
     * Started is enough: an application boots without searching anything.
     */
    public function getLinkDependencies(): array
    {
        return [$this->getName() => 'service_started'];
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('expose', $this->getName(), description: 'Expose the meilisearch service over TCP on the host (--stop to stop)'),
            'function' => function (
                #[AsArgument(description: 'Host port to expose on (defaults to the service port)')]
                ?int $port = null,
                bool $stop = false,
            ): void {
                expose_service_port($this->getName(), 7700, $port, $stop);
            },
        ];
    }
}
