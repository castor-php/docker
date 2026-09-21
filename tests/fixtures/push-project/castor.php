<?php

/*
 * A project whose only purpose is to be pushed. It registers no service of the
 * plugin — those all build a real PHP, Node, Go or Rust image, which this has
 * no use for — and declares three cheap builds instead, one per case
 * "docker:push" has to tell apart:
 *
 *  - "cached", the ordinary one, whose cache_from is a full
 *    "type=registry,ref=..." entry;
 *  - "shorthand", whose cache_from is the bare image reference compose also
 *    accepts, and buildx does not accept in a cache-to;
 *  - "uncached", which builds but has nowhere to push a cache, and must stay
 *    out of what bake is asked to build.
 *
 * The registry comes from the environment: the test starts a throwaway one and
 * only knows its address at that point.
 */

namespace push;

use Castor\Attribute\AsContext;
use Castor\Context;
use Castor\Docker\Attribute\AsDockerComposeBuilder;
use Castor\Docker\Service\Builder\ComposeBuilder;

defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', false);

#[AsContext(default: true)]
function default_context(): Context
{
    return new Context([
        'root_domain' => 'push.test',
        'registry' => getenv('CASTOR_DOCKER_TEST_REGISTRY') ?: '',
    ]);
}

#[AsDockerComposeBuilder]
function add_builds(ComposeBuilder $builder, Context $context): void
{
    $registry = $context->data['registry'] ?? '';

    $builder
        ->service('cached')
            ->build()
                ->context(__DIR__ . '/app')
                ->arg('greeting', 'cached')
                ->withRegistryCache('cached')
            ->end()
            ->command(['sleep', 'infinity'])
        ->end()
        ->service('shorthand')
            ->build()
                ->context(__DIR__ . '/app')
                ->arg('greeting', 'shorthand')
                // The shorthand compose accepts, on purpose.
                ->cacheFrom("{$registry}/shorthand:cache")
            ->end()
            ->command(['sleep', 'infinity'])
        ->end()
        ->service('uncached')
            ->build()
                ->context(__DIR__ . '/app')
                ->arg('greeting', 'uncached')
            ->end()
            ->command(['sleep', 'infinity'])
        ->end()
    ;
}
