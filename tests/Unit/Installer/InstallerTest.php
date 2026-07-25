<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Installer;

use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Installer\ListenerEditor;
use Castor\Docker\Installer\MariaDBInstaller;
use Castor\Docker\Installer\RustInstaller;
use Castor\Docker\Installer\SymfonyInstaller;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    private const LISTENER = <<<'PHP'
        <?php

        namespace project;

        use Castor\Attribute\AsListener;
        use Castor\Docker\Event\RegisterServiceEvent;

        #[AsListener(RegisterServiceEvent::class)]
        function register_service(RegisterServiceEvent $event): void
        {
        }

        PHP;

    private function install(object $installer, array $answers, string $source = self::LISTENER): string
    {
        $editor = new ListenerEditor($source);
        $builder = new ServiceStatementBuilder($editor->getEventVariable());
        $installer->buildStatements($builder, $answers);
        $editor->addImports($builder->getImports());
        $editor->addStatements($builder->getStatements());

        return $editor->getSource();
    }

    public function testMariaDbInstaller(): void
    {
        $result = $this->install(new MariaDBInstaller(), ['version' => '11.4']);

        static::assertStringContainsString('use Castor\Docker\Service\MariaDBService;', $result);
        static::assertStringContainsString("\$event->addService(new MariaDBService(version: '11.4'));", $result);
    }

    public function testSymfonyInstallerWithDatabaseLink(): void
    {
        $result = $this->install(new SymfonyInstaller(), [
            'name' => 'blog',
            'directory' => 'blog',
            'version' => '8.4',
            'mode' => 'frankenphp',
            'domain' => 'blog.test',
            'symfony_version' => '',
            'database' => 'postgres',
        ]);

        static::assertStringContainsString('use Castor\Docker\Service\PhpMode;', $result);
        static::assertStringContainsString('use Castor\Docker\Service\SymfonyService;', $result);
        static::assertStringContainsString(
            "\$event->addService((new SymfonyService(name: 'blog', directory: __DIR__ . '/blog', version: '8.4', mode: PhpMode::FrankenPhp))->addDomain('blog.test')->withDatabaseService(\$postgres));",
            $result,
        );
    }

    public function testRustInstaller(): void
    {
        $result = $this->install(new RustInstaller(), [
            'name' => 'api',
            'directory' => 'api',
            'version' => '1.90',
            'port' => 3000,
            'domain' => 'api.test',
        ]);

        static::assertStringContainsString('use Castor\Docker\Service\RustService;', $result);
        static::assertStringContainsString(
            "\$event->addService((new RustService(name: 'api', version: '1.90', directory: __DIR__ . '/api', port: 3000))->addDomain('api.test'));",
            $result,
        );
    }

    public function testExtractsInlineDatabaseToVariable(): void
    {
        $source = <<<'PHP'
            <?php

            namespace project;

            use Castor\Attribute\AsListener;
            use Castor\Docker\Event\RegisterServiceEvent;
            use Castor\Docker\Service\PostgresService;

            #[AsListener(RegisterServiceEvent::class)]
            function register_service(RegisterServiceEvent $event): void
            {
                $event->addService(new PostgresService());
            }

            PHP;

        $editor = new ListenerEditor($source);
        $variable = $editor->ensureServiceVariable(\Castor\Docker\Service\PostgresService::class, 'postgres');

        static::assertSame('postgres', $variable);

        $result = $editor->getSource();
        static::assertStringContainsString('$postgres = new PostgresService();', $result);
        static::assertStringContainsString('$event->addService($postgres);', $result);
    }
}
