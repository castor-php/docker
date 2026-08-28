<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Castor\Docker\describe_command;
use function Castor\Docker\to_container_command;

/**
 * A command given as tokens is handed to docker as it is, so nothing in the
 * container splits or expands it. A command given as a string keeps the shell
 * it has always had, since it may be written to use one.
 */
final class ContainerCommandTest extends TestCase
{
    public function testTokensAreHandedOverUntouched(): void
    {
        static::assertSame(
            ['php', 'bin/console', 'app:import'],
            to_container_command(['php', 'bin/console', 'app:import']),
        );
    }

    /**
     * The reason for all of this: an argument holding a space, a quote or a
     * "$" used to be split by the shell, or expanded by it.
     */
    public function testAnArgumentIsNeverSplitNorExpanded(): void
    {
        $args = ['php', 'bin/console', 'app:notify', 'two words', 'say "hi"', '$HOME', 'a;b', '^1.0 || ^2.0'];

        static::assertSame($args, to_container_command($args));
    }

    public function testAStringStillRunsThroughAShell(): void
    {
        static::assertSame(
            ['/bin/sh', '-c', 'exec rm -rf var/cache && echo done'],
            to_container_command('rm -rf var/cache && echo done'),
        );
    }

    /**
     * "exec" replaces the shell rather than leaving it waiting, so signals
     * reach the command and no process is left in between.
     */
    public function testTheShellIsReplacedByTheCommand(): void
    {
        static::assertStringStartsWith('exec ', to_container_command('bash')[2]);
    }

    public function testAFailureNamesTheCommandInEitherForm(): void
    {
        static::assertSame('php bin/console app:import', describe_command(['php', 'bin/console', 'app:import']));
        static::assertSame('composer install', describe_command('composer install'));
    }

    /**
     * A list with gaps in its keys would reach docker with the keys as
     * arguments, so the tokens are renumbered.
     */
    public function testTokensWithGapsAreRenumbered(): void
    {
        $sparse = array_filter(['php', '', 'bin/console'], static fn(string $t): bool => '' !== $t);

        static::assertSame(['php', 'bin/console'], to_container_command($sparse));
    }
}
