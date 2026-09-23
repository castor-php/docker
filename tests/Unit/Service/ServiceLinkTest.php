<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\GoService;
use Castor\Docker\Service\MailpitService;
use Castor\Docker\Service\MeilisearchService;
use Castor\Docker\Service\MercureService;
use Castor\Docker\Service\PHPService;
use Castor\Docker\Service\PhpMode;
use Castor\Docker\Service\PostgresService;
use Castor\Docker\Service\RustFSService;
use Castor\Docker\Service\ServiceInterface;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;

final class ServiceLinkTest extends TestCase
{
    private function context(?string $worktree = null): Context
    {
        return new Context(
            data: [
                'project_name' => 'demo',
                'root_domain' => null === $worktree ? 'demo.test' : $worktree . '.demo.test',
                'worktree' => $worktree,
                // The shared home of the main checkout is asked to git.
                'worktree_shared_home' => false,
                'user_id' => 1000,
            ],
            workingDirectory: '/project',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Context $context, ServiceInterface ...$services): array
    {
        $builder = new ComposeBuilder();

        foreach ($services as $service) {
            $builder = $service->updateCompose($context, $builder);
        }

        return $builder->toArray();
    }

    /**
     * The deprecated methods still replace the previous database instead of
     * adding a second one, until they are removed in 1.0.
     */
    #[IgnoreDeprecations]
    public function testWithDatabaseServiceReplacesThePreviousOne(): void
    {
        $this->expectUserDeprecationMessage('Castor\\Docker\\Service\\PHPService::withDatabaseService() is deprecated since castor-php/docker 0.8 and will be removed in 1.0, use link() instead.');
        $this->expectUserDeprecationMessage('Castor\\Docker\\Service\\PHPService::withMailerService() is deprecated since castor-php/docker 0.8 and will be removed in 1.0, use link() instead.');

        $app = (new PHPService('app'))
            ->withDirectory('/project/app')
            ->withDatabaseService(new PostgresService())
            ->withDatabaseService((new PostgresService())->withName('analytics'))
            ->withMailerService(new MailpitService())
        ;

        static::assertSame(['analytics', 'mailpit'], array_keys($app->getLinks()));

        $compose = $this->build($this->context(), $app);

        static::assertStringContainsString('@analytics:5432/', $compose['services']['app']['environment']['DATABASE_URL']);
        static::assertSame('smtp://mailpit:1025', $compose['services']['app-builder']['environment']['MAILER_DSN']);
    }

    public function testTwoLinksProvidingTheSameVariableAreRefused(): void
    {
        $app = (new PHPService('app'))
            ->withDirectory('/project/app')
            ->link(new PostgresService())
            ->link((new PostgresService())->withName('analytics'))
        ;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"postgres" and "analytics", which both provide the DATABASE_URL variable');

        $this->build($this->context(), $app);
    }

    /**
     * Every application service consumes links, not only the PHP ones.
     */
    public function testAGoApplicationCanBeLinked(): void
    {
        $rustfs = new RustFSService();

        $compose = $this->build($this->context(), (new GoService('api'))->withDirectory('/project/api')->link($rustfs));

        static::assertSame('http://rustfs:9000', $compose['services']['api']['environment']['AWS_ENDPOINT_URL_S3']);
        static::assertSame('service_started', $compose['services']['api']['depends_on']['rustfs']['condition']);
    }

    public function testPublicUrlsFollowTheWorktree(): void
    {
        $context = $this->context('wt2');
        $mercure = new MercureService();
        $meilisearch = new MeilisearchService();
        $rustfs = new RustFSService();

        $compose = $this->build($context, (new PHPService('app'))
            ->withDirectory('/project/app')
            ->link($mercure)
            ->link($meilisearch)
            ->link($rustfs));

        $environment = $compose['services']['app']['environment'];

        static::assertSame('https://mercure.wt2.demo.test/.well-known/mercure', $environment['MERCURE_PUBLIC_URL']);
        static::assertSame('https://meilisearch.wt2.demo.test', $environment['MEILISEARCH_PUBLIC_URL']);
        static::assertSame('https://rustfs.wt2.demo.test', $environment['S3_PUBLIC_ENDPOINT']);
    }

    /**
     * A domain spelled out in castor.php is moved under the worktree once the
     * compose file is built: the hub has to allow the moved one.
     */
    public function testTheHubAllowsTheWorktreeDomainsOfItsApplications(): void
    {
        $context = $this->context('wt2');
        $mercure = (new MercureService())->withCorsOrigin('https://legacy.demo.test:8443', 'https://front.other.test');

        (new PHPService('app'))->withDomain('app.demo.test', 'localhost')->link($mercure);

        static::assertSame(
            ['https://app.wt2.demo.test', 'https://localhost', 'https://legacy.wt2.demo.test:8443', 'https://front.other.test'],
            $mercure->getCorsOrigins($context),
        );
    }

    public function testTheEmbeddedHubIsServedOnTheWorktreeDomain(): void
    {
        $mercure = (new MercureService())->withJwtSecret('a-secret-long-enough-for-hs256-signatures');

        $compose = $this->build($this->context('wt2'), $mercure, (new PHPService('app'))
            ->withDirectory('/project/app')
            ->withDomain('app.demo.test')
            ->link($mercure));

        $environment = $compose['services']['app']['environment'];

        static::assertArrayNotHasKey('mercure', $compose['services']);
        static::assertArrayNotHasKey('depends_on', $compose['services']['app']);
        static::assertSame('http://app/.well-known/mercure', $environment['MERCURE_URL']);
        static::assertSame('https://app.wt2.demo.test/.well-known/mercure', $environment['MERCURE_PUBLIC_URL']);
        static::assertSame('https://app.wt2.demo.test', $environment['MERCURE_CORS_ORIGINS']);
        static::assertSame('a-secret-long-enough-for-hs256-signatures', $compose['services']['app-builder']['environment']['MERCURE_JWT_SECRET']);
    }

    /**
     * PHP-FPM has no Caddy to embed the hub in.
     */
    public function testAnFpmApplicationUsesTheContainer(): void
    {
        $mercure = new MercureService();

        $compose = $this->build($this->context(), $mercure, (new PHPService('app'))
            ->withDirectory('/project/app')
            ->withMode(PhpMode::Fpm)
            ->withDomain('app.demo.test')
            ->link($mercure));

        static::assertArrayHasKey('mercure', $compose['services']);
        static::assertSame('http://mercure/.well-known/mercure', $compose['services']['app']['environment']['MERCURE_URL']);
        static::assertArrayNotHasKey('mercure', $compose['services']['app']['build']['args']);
    }

    /**
     * The browsers reach an embedded hub on the domain of the application.
     */
    public function testAnApplicationWithoutDomainUsesTheContainer(): void
    {
        $mercure = new MercureService();

        (new PHPService('app'))->link($mercure);

        static::assertNull($mercure->getHost());
    }

    /**
     * One MercureService is one hub: two applications linked to it share the
     * container, rather than each getting a hub of its own that the other one
     * never hears from.
     */
    public function testASharedHubIsAContainer(): void
    {
        $mercure = new MercureService();

        $compose = $this->build(
            $this->context(),
            $mercure,
            (new PHPService('front'))->withDirectory('/project/front')->withDomain('front.demo.test')->link($mercure),
            (new PHPService('back'))->withDirectory('/project/back')->withDomain('back.demo.test')->link($mercure),
        );

        static::assertArrayHasKey('mercure', $compose['services']);
        static::assertSame('http://mercure/.well-known/mercure', $compose['services']['front']['environment']['MERCURE_URL']);
        static::assertStringContainsString('cors_origins https://front.demo.test https://back.demo.test', $compose['services']['mercure']['environment']['MERCURE_EXTRA_DIRECTIVES']);
    }

    /**
     * No bucket, no one-shot container to wait for.
     */
    public function testRustfsWithoutBucketsHasNoBucketsContainer(): void
    {
        $rustfs = new RustFSService();
        $compose = $this->build($this->context(), $rustfs);

        static::assertArrayNotHasKey('rustfs-buckets', $compose['services']);
        static::assertSame(['rustfs' => 'service_started'], $rustfs->getLinkDependencies());
    }

    public function testAnInvalidBucketNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RustFSService())->withBucket('Uploads; rm -rf /');
    }
}
