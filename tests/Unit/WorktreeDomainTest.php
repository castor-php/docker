<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\add_project_extra_hosts;
use function Castor\Docker\apply_worktree_domains;
use function Castor\Docker\get_worktree_base_domain;
use function Castor\Docker\worktree_domain;

/**
 * A worktree answers under a label of its own inserted right before the root
 * domain, so the services deriving their domain from "root_domain" and the ones
 * a project spells out end up on the same shape — the latter being why the
 * rewrite exists: a castor.php knows nothing about the checkout it runs in.
 */
final class WorktreeDomainTest extends TestCase
{
    private function context(?string $worktree, ?string $rootDomain = 'myproject.test'): Context
    {
        $data = ['worktree' => $worktree];

        if (null !== $rootDomain) {
            // What initialize_project() leaves on the context: already prefixed.
            $data['root_domain'] = null === $worktree ? $rootDomain : $worktree . '.' . $rootDomain;
        }

        return new Context(data: $data, workingDirectory: '/project');
    }

    public function testTheBaseDomainIsTheOneOfTheMainCheckout(): void
    {
        static::assertSame('myproject.test', get_worktree_base_domain($this->context('wt2')));
        static::assertSame('myproject.test', get_worktree_base_domain($this->context(null)));
    }

    public function testTheRootDomainBecomesTheSubdomainOfTheWorktree(): void
    {
        static::assertSame('wt2.myproject.test', worktree_domain('myproject.test', $this->context('wt2')));
    }

    public function testASubdomainKeepsItsLabelsInFrontOfTheWorktree(): void
    {
        $c = $this->context('wt2');

        static::assertSame('app.wt2.myproject.test', worktree_domain('app.myproject.test', $c));
        static::assertSame('admin.app.wt2.myproject.test', worktree_domain('admin.app.myproject.test', $c));
    }

    /**
     * A project already deriving its domains from the root domain — which is the
     * prefixed one — must not be prefixed twice.
     */
    public function testTheRewriteIsIdempotent(): void
    {
        $c = $this->context('wt2');

        static::assertSame('app.wt2.myproject.test', worktree_domain('app.wt2.myproject.test', $c));
        static::assertSame('wt2.myproject.test', worktree_domain('wt2.myproject.test', $c));
        static::assertSame('app.wt2.myproject.test', worktree_domain(worktree_domain('app.myproject.test', $c), $c));
    }

    /**
     * Nothing says what a domain outside of the project's own root should become,
     * so it is left alone — and reported by "docker:about" instead.
     */
    public function testADomainOutsideOfTheRootDomainIsLeftAlone(): void
    {
        static::assertSame('somethingelse.test', worktree_domain('somethingelse.test', $this->context('wt2')));
        static::assertSame('myproject.test.evil.com', worktree_domain('myproject.test.evil.com', $this->context('wt2')));
    }

    /**
     * A domain whose *label* merely ends with the root domain is not a subdomain
     * of it.
     */
    public function testAnUnrelatedDomainSharingTheSuffixIsLeftAlone(): void
    {
        static::assertSame('notmyproject.test', worktree_domain('notmyproject.test', $this->context('wt2')));
    }

    public function testTheMainCheckoutRewritesNothing(): void
    {
        static::assertSame('app.myproject.test', worktree_domain('app.myproject.test', $this->context(null)));
    }

    public function testTheRouterLabelsFollowTheRewrittenDomains(): void
    {
        $c = $this->context('wt2');
        $builder = new ComposeBuilder();
        $builder->service('app')->withHttpRouting(['myproject.test', 'app.myproject.test'], 80, allowHttpAccess: true)->end();

        apply_worktree_domains($c, $builder);

        $labels = $builder->toArray()['services']['app']['labels'];

        static::assertContains('caddy=wt2.myproject.test app.wt2.myproject.test', $labels);
        static::assertContains('caddy_1=http://wt2.myproject.test http://app.wt2.myproject.test', $labels);
        static::assertSame(['wt2.myproject.test', 'app.wt2.myproject.test'], $builder->service('app')->getRoutedDomains());
    }

    /**
     * Each site keeps its own domains: the console of an object storage must
     * not end up answering on the domain of its API.
     */
    public function testEachSiteIsRewrittenOnItsOwn(): void
    {
        $builder = new ComposeBuilder();
        $builder->service('rustfs')
            ->withHttpRouting('rustfs.myproject.test', 9000)
            ->withHttpRouting('rustfs-console.myproject.test', 9001, allowHttpAccess: true)
        ->end();

        apply_worktree_domains($this->context('wt2'), $builder);

        $labels = $builder->toArray()['services']['rustfs']['labels'];

        static::assertContains('caddy=rustfs.wt2.myproject.test', $labels);
        static::assertContains('caddy_2=rustfs-console.wt2.myproject.test', $labels);
        static::assertContains('caddy_3=http://rustfs-console.wt2.myproject.test', $labels);
        static::assertSame(['rustfs.wt2.myproject.test', 'rustfs-console.wt2.myproject.test'], $builder->service('rustfs')->getRoutedDomains());
    }

    /**
     * The "caddy_1" plain-HTTP site is only valid after the "caddy" one it
     * duplicates, so the rewrite must not reorder the labels.
     */
    public function testTheRewriteKeepsTheLabelsInOrder(): void
    {
        $builder = new ComposeBuilder();
        $builder->service('app')->withHttpRouting('app.myproject.test', 80, allowHttpAccess: true)->end();

        $before = $builder->toArray()['services']['app']['labels'];
        apply_worktree_domains($this->context('wt2'), $builder);
        $after = $builder->toArray()['services']['app']['labels'];

        static::assertSame(
            array_map(static fn(string $label): string => explode('=', $label)[0], $before),
            array_map(static fn(string $label): string => explode('=', $label)[0], $after),
        );
    }

    public function testTheMainCheckoutKeepsItsLabelsByteForByte(): void
    {
        $builder = new ComposeBuilder();
        $builder->service('app')->withHttpRouting('app.myproject.test', 80, allowHttpAccess: true)->end();

        $before = $builder->toArray();
        apply_worktree_domains($this->context(null), $builder);

        static::assertSame($before, $builder->toArray());
    }

    /**
     * A container reaching the project's own public API must resolve the domain
     * the worktree really serves, so the rewrite has to happen first.
     */
    public function testTheExtraHostsUseTheRewrittenDomains(): void
    {
        $c = $this->context('wt2');
        $builder = new ComposeBuilder();
        $builder->service('app')->withHttpRouting('api.myproject.test', 80)->end();

        apply_worktree_domains($c, $builder);
        add_project_extra_hosts($c, $builder);

        static::assertSame(
            ['api.wt2.myproject.test:host-gateway'],
            $builder->toArray()['services']['app']['extra_hosts'],
        );
    }

    /**
     * Two domains collapsing onto the same one would emit it twice in the same
     * router label.
     */
    public function testTheRewrittenDomainsAreDeduplicated(): void
    {
        $c = $this->context('wt2');
        $builder = new ComposeBuilder();
        $builder->service('app')->withHttpRouting(['myproject.test', 'wt2.myproject.test'], 80)->end();

        apply_worktree_domains($c, $builder);

        static::assertSame(['wt2.myproject.test'], $builder->service('app')->getRoutedDomains());
        static::assertContains('caddy=wt2.myproject.test', $builder->toArray()['services']['app']['labels']);
    }
}
