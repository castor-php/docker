<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Installer;

use Castor\Docker\Installer\Ast\Ast;
use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Installer\ListenerEditor;
use Castor\Docker\Service\MariaDBService;
use Castor\Docker\Service\SymfonyService;
use PHPUnit\Framework\TestCase;

final class ListenerEditorTest extends TestCase
{
    public function testAppendsServiceToExistingListener(): void
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
        $builder = new ServiceStatementBuilder($editor->getEventVariable());
        $builder->addNewServiceAst(MariaDBService::class, ['version' => '12.1']);
        $editor->addImports($builder->getImports());
        $editor->addStatements($builder->getStatements());

        $result = $editor->getSource();

        // Untouched lines are preserved verbatim.
        static::assertStringContainsString('$event->addService(new PostgresService());', $result);
        // New registration + import were added.
        static::assertStringContainsString('use Castor\Docker\Service\MariaDBService;', $result);
        static::assertStringContainsString('$event->addService(new MariaDBService(version: \'12.1\'));', $result);
    }

    public function testCreatesListenerWhenMissing(): void
    {
        $source = <<<'PHP'
            <?php

            namespace project;

            use Castor\Attribute\AsContext;
            use Castor\Context;

            #[AsContext(default: true)]
            function default_context(): Context
            {
                return new Context();
            }

            PHP;

        $editor = new ListenerEditor($source);
        $builder = new ServiceStatementBuilder($editor->getEventVariable());
        $builder->addNewServiceAst(MariaDBService::class);
        $editor->addImports($builder->getImports());
        $editor->addStatements($builder->getStatements());

        $result = $editor->getSource();

        static::assertStringContainsString('use Castor\Attribute\AsListener;', $result);
        static::assertStringContainsString('use Castor\Docker\Event\RegisterServiceEvent;', $result);
        static::assertStringContainsString('#[AsListener(RegisterServiceEvent::class)]', $result);
        static::assertStringContainsString('function register_service(RegisterServiceEvent $event): void', $result);
        static::assertStringContainsString('$event->addService(new MariaDBService());', $result);
        // The existing function is left intact.
        static::assertStringContainsString('function default_context(): Context', $result);
    }

    public function testFluentCallsAndRawArguments(): void
    {
        $source = <<<'PHP'
            <?php

            namespace project;

            use Castor\Attribute\AsListener;
            use Castor\Docker\Event\RegisterServiceEvent;

            #[AsListener(RegisterServiceEvent::class)]
            function register_service(RegisterServiceEvent $event): void
            {
            }

            PHP;

        $editor = new ListenerEditor($source);
        $builder = new ServiceStatementBuilder($editor->getEventVariable());
        $builder->addNewServiceAst(SymfonyService::class, ['name' => 'blog', 'directory' => Ast::raw("__DIR__ . '/blog'")])
            ->callMethod('addDomain', ['blog.test'])
            ->callMethod('allowHttpAccess')
        ;
        $editor->addImports($builder->getImports());
        $editor->addStatements($builder->getStatements());

        $result = $editor->getSource();

        static::assertStringContainsString(
            "\$event->addService((new SymfonyService(name: 'blog', directory: __DIR__ . '/blog'))->addDomain('blog.test')->allowHttpAccess());",
            $result,
        );
    }
}
