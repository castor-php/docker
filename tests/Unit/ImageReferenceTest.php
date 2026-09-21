<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Castor\Docker\get_cache_reference;
use function Castor\Docker\get_image_reference;
use function Castor\Docker\normalize_source_url;

/**
 * A build cache carries no label, so nothing in it tells GitHub which
 * repository a package belongs to. "docker:push" publishes an image next to
 * the cache for that — same package, another tag, and the labels the registry
 * reads — and getting the reference or the URL wrong means either pushing
 * somewhere else or attaching the package to nothing.
 */
final class ImageReferenceTest extends TestCase
{
    public function testTheImageSitsInTheRepositoryOfTheCache(): void
    {
        static::assertSame(
            'ghcr.io/acme/app:latest',
            get_image_reference('ghcr.io/acme/app:cache', 'latest'),
        );
    }

    /**
     * The colon of a registry running on a port is not the one that opens a
     * tag — a test registry, or any self-hosted one, reads as "host:5000/app".
     */
    public function testAPortIsNotATag(): void
    {
        static::assertSame(
            'localhost:5000/acme/app:latest',
            get_image_reference('localhost:5000/acme/app', 'latest'),
        );
        static::assertSame(
            'localhost:5000/acme/app:latest',
            get_image_reference('localhost:5000/acme/app:cache', 'latest'),
        );
    }

    public function testADigestIsDropped(): void
    {
        static::assertSame(
            'ghcr.io/acme/app:latest',
            get_image_reference('ghcr.io/acme/app@sha256:' . str_repeat('a', 64), 'latest'),
        );
    }

    public function testTheCacheReferenceIsTheRefOfARegistryEntry(): void
    {
        static::assertSame(
            'ghcr.io/acme/app:cache',
            get_cache_reference('type=registry,ref=ghcr.io/acme/app:cache'),
        );
        static::assertSame(
            'ghcr.io/acme/app:cache',
            get_cache_reference('ref=ghcr.io/acme/app:cache,type=registry'),
        );
    }

    /**
     * A cache stored outside of a registry names no repository to publish an
     * image to: that service keeps pushing its cache, and only its cache.
     */
    public function testACacheOutsideOfARegistryHasNoReference(): void
    {
        static::assertNull(get_cache_reference('type=gha,scope=build'));
        static::assertNull(get_cache_reference('type=inline'));
        static::assertNull(get_cache_reference('type=local,dest=/tmp/cache'));
    }

    public function testEveryFormOfARemoteBecomesABrowsableUrl(): void
    {
        static::assertSame('https://github.com/acme/app', normalize_source_url('git@github.com:acme/app.git'));
        static::assertSame('https://github.com/acme/app', normalize_source_url('ssh://git@github.com/acme/app.git'));
        static::assertSame('https://github.com/acme/app', normalize_source_url('https://github.com/acme/app.git'));
        static::assertSame('https://github.com/acme/app', normalize_source_url('https://github.com/acme/app'));
        // The shorthand a context holds, rather than a remote.
        static::assertSame('https://github.com/acme/app', normalize_source_url('acme/app'));
    }
}
