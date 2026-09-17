<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Installer;

use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Installer\Input;
use Castor\Docker\Installer\InputType;
use Castor\Docker\Installer\ListenerEditor;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\format_choice_default;

/**
 * A choice an installer marks as multiple is answered with several of its
 * choices at once, so the answer is a list where the others hold a string.
 */
final class MultipleChoiceInputTest extends TestCase
{
    public function testAChoiceIsSingleUnlessSaidOtherwise(): void
    {
        $input = new Input('mode', 'Mode', InputType::Choice, 'fpm', ['fpm', 'cli']);

        static::assertFalse($input->multiple);
    }

    /**
     * Symfony preselects a multiple choice from one comma-separated string, so
     * a list default has to reach it written that way — anything else would be
     * dropped, and the prompt would start on nothing.
     */
    public function testAListDefaultIsPreselected(): void
    {
        static::assertSame('redis,mailer', format_choice_default(['redis', 'mailer']));
    }

    public function testASingleDefaultIsLeftAlone(): void
    {
        static::assertSame('fpm', format_choice_default('fpm'));
    }

    public function testNoDefaultPreselectsNothing(): void
    {
        static::assertNull(format_choice_default(null));
        static::assertNull(format_choice_default([]));
    }

    /**
     * The answer being a list has to survive all the way to the listener the
     * install writes, or a multiple choice could only ever be asked.
     */
    public function testAListAnswerIsWrittenToTheListener(): void
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
        $builder->addNewServiceAst(\Castor\Docker\Service\PHPService::class, ['app'])
            ->callMethod('addExtension', ['php/kafka', ['librdkafka-dev', 'zlib-dev']]);
        $editor->addImports($builder->getImports());
        $editor->addStatements($builder->getStatements());

        static::assertStringContainsString("addExtension('php/kafka', ['librdkafka-dev', 'zlib-dev'])", $editor->getSource());
    }
}
