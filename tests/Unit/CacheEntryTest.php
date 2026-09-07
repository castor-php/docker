<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Castor\Docker\normalize_cache_entry;

/**
 * "docker:push" turns the cache_from of every service into the cache-to it
 * hands to "docker buildx bake --set". Compose accepts a bare image reference
 * there, buildx does not — it answers "invalid value <ref>" and the whole push
 * fails — so the shorthand has to be spelled out on the way.
 */
final class CacheEntryTest extends TestCase
{
    public function testAShorthandReferenceIsSpelledOut(): void
    {
        static::assertSame(
            'type=registry,ref=ghcr.io/acme/app:cache',
            normalize_cache_entry('ghcr.io/acme/app:cache'),
        );
    }

    /**
     * What BuildBuilder::registryCache() generates, and what a hand-written
     * compose file usually holds: already a full entry, and left alone.
     */
    public function testAnEntryAlreadyNamingItsTypeIsLeftAlone(): void
    {
        static::assertSame(
            'type=registry,ref=ghcr.io/acme/app:cache',
            normalize_cache_entry('type=registry,ref=ghcr.io/acme/app:cache'),
        );
    }

    /**
     * The registry is not the only cache buildx exports to, and a "type" that
     * needs no "ref" is still a "type".
     */
    public function testANonRegistryCacheIsLeftAlone(): void
    {
        static::assertSame('type=gha,scope=build', normalize_cache_entry('type=gha,scope=build'));
        static::assertSame('type=inline', normalize_cache_entry('type=inline'));
    }

    /**
     * The fields of an entry are unordered, so "type" is not necessarily the
     * one the value starts with.
     */
    public function testTheTypeIsFoundWhereverItSits(): void
    {
        static::assertSame(
            'ref=ghcr.io/acme/app:cache,type=registry',
            normalize_cache_entry('ref=ghcr.io/acme/app:cache,type=registry'),
        );
    }
}
