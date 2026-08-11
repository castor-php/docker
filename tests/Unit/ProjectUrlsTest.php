<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\get_project_urls;

/**
 * "docker:about" lists every address the project answers on, read from the
 * compose files: it has to answer whether or not the infrastructure runs, and
 * without a docker daemon.
 */
final class ProjectUrlsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'compose');
        \assert($directory !== false);
        unlink($directory);
        mkdir($directory);

        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    private function write(string $file, string $content): void
    {
        file_put_contents($this->directory . '/' . $file, $content);
    }

    private function context(): Context
    {
        return new Context(workingDirectory: $this->directory);
    }

    public function testNoComposeFileYieldsNoUrl(): void
    {
        static::assertSame([], get_project_urls($this->context()));
    }

    /**
     * The "caddy" label carries the HTTPS site, "caddy_1" the plain HTTP one
     * withHttpAccess() adds, and both may serve several domains at once.
     */
    public function testTheCaddyLabelsBecomeUrls(): void
    {
        $this->write('compose.generated.yaml', <<<'YAML'
            services:
                app:
                    labels:
                        - 'caddy=app.project.test project.test'
                        - 'caddy.reverse_proxy={{upstreams 80}}'
                        - caddy.tls=internal
                        - 'caddy_1=http://app.project.test http://project.test'
                redis-insight:
                    labels:
                        - caddy=redis.project.test
            YAML);

        static::assertSame([
            'app' => [
                'https://app.project.test',
                'https://project.test',
                'http://app.project.test',
                'http://project.test',
            ],
            'redis-insight' => ['https://redis.project.test'],
        ], get_project_urls($this->context()));
    }

    /**
     * A domain declared straight in a project's own compose file is served the
     * same way, and compose accepts its labels as a map too.
     */
    public function testTheProjectOwnComposeFilesCountAndAcceptMapLabels(): void
    {
        $this->write('compose.generated.yaml', "services:\n    app:\n        labels:\n            - caddy=app.project.test\n");
        $this->write('compose.override.yaml', <<<'YAML'
            services:
                mailpit:
                    labels:
                        caddy: mail.project.test
                        caddy.reverse_proxy: '{{upstreams 8025}}'
            YAML);

        static::assertSame([
            'app' => ['https://app.project.test'],
            'mailpit' => ['https://mail.project.test'],
        ], get_project_urls($this->context()));
    }

    /**
     * The label value is a caddy site address: it may hold a matcher, or a
     * placeholder nothing interpolated, neither of which is a URL to print.
     */
    public function testWhatIsNotAHostNameIsSkipped(): void
    {
        $this->write('compose.generated.yaml', <<<'YAML'
            services:
                app:
                    labels:
                        - 'caddy=app.project.test ${PROJECT_ROOT_DOMAIN} :8080'
                        - caddy.tls=internal
            YAML);

        static::assertSame(['app' => ['https://app.project.test']], get_project_urls($this->context()));
    }

    /**
     * A service is only listed once, however many files route to it.
     */
    public function testTheSameDomainIsNotListedTwice(): void
    {
        $this->write('compose.generated.yaml', "services:\n    app:\n        labels:\n            - caddy=app.project.test\n");
        $this->write('compose.override.yaml', "services:\n    app:\n        labels:\n            - caddy=app.project.test\n");

        static::assertSame(['app' => ['https://app.project.test']], get_project_urls($this->context()));
    }
}
