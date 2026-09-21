<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\extract_worktree_option;
use function Castor\Docker\get_exposed_services_cache_key;
use function Castor\Docker\resolve_worktree_path;
use function Castor\Docker\worktree_project_name;
use function Castor\Docker\worktree_root_domain;

final class WorktreeLayoutTest extends TestCase
{
    public function testWorktreesLiveBesideTheMainCheckoutByDefault(): void
    {
        static::assertSame(
            '/home/me/work/worktrees/app/bug-4242',
            resolve_worktree_path('bug-4242', '/home/me/work/app', null),
        );
    }

    public function testARelativeDirectoryIsResolvedAgainstTheParentOfTheMainCheckout(): void
    {
        static::assertSame(
            '/home/me/work/branches/bug-4242',
            resolve_worktree_path('bug-4242', '/home/me/work/app', 'branches'),
        );
    }

    public function testAnAbsoluteDirectoryIsTakenAsIs(): void
    {
        static::assertSame(
            '/tmp/wt/bug-4242',
            resolve_worktree_path('bug-4242', '/home/me/work/app', '/tmp/wt'),
        );
    }

    /**
     * The layout the editors creating worktrees on their own use, where the
     * checkout repeats the name of the repository.
     */
    public function testThePlaceholderIsWhereTheNameGoes(): void
    {
        static::assertSame(
            '/home/me/work/worktrees/bug-4242/app',
            resolve_worktree_path('bug-4242', '/home/me/work/app', 'worktrees/{name}/app'),
        );
    }

    public function testTheNamesOfACheckoutFollowFromItsWorktree(): void
    {
        static::assertSame('app', worktree_project_name(null, 'app'));
        static::assertSame('app-bug-4242', worktree_project_name('bug-4242', 'app'));
        static::assertSame('myproject.test', worktree_root_domain(null, 'myproject.test'));
        static::assertSame('bug-4242.myproject.test', worktree_root_domain('bug-4242', 'myproject.test'));
    }

    /**
     * @return iterable<string, array{list<string>, ?string, list<string>}>
     */
    public static function provideArguments(): iterable
    {
        yield 'no option' => [['mysql:expose', '3307'], null, ['mysql:expose', '3307']];
        yield 'separate value' => [['--worktree', 'wt2', 'docker:up'], 'wt2', ['docker:up']];
        yield 'inline value' => [['--worktree=wt2', 'docker:up'], 'wt2', ['docker:up']];
        yield 'main' => [['docker:logs', '--worktree', 'main'], 'main', ['docker:logs']];
        yield 'after the task' => [['docker:up', '--worktree=wt2', '--build'], 'wt2', ['docker:up', '--build']];
        // Everything after "--" belongs to the task, "--worktree" included.
        yield 'raw tokens' => [['run', '--', '--worktree', 'wt2'], null, ['run', '--', '--worktree', 'wt2']];
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $expectedForwarded
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideArguments')]
    public function testTheWorktreeOptionIsReadOutOfTheRawArguments(array $arguments, ?string $expectedName, array $expectedForwarded): void
    {
        [$name, $forwarded] = extract_worktree_option($arguments);

        static::assertSame($expectedName, $name);
        static::assertSame($expectedForwarded, $forwarded);
    }

    /**
     * Castor's cache is one directory shared by every project of the machine, so
     * an unscoped key made "docker:up" restore the port forwarders of whatever
     * checkout exposed a service last.
     */
    public function testTheExposedServicesAreRememberedPerCheckout(): void
    {
        $main = get_exposed_services_cache_key(new Context(workingDirectory: '/home/me/work/app'));
        $worktree = get_exposed_services_cache_key(new Context(workingDirectory: '/home/me/work/worktrees/app/bug-4242'));

        static::assertNotSame($main, $worktree);
        static::assertSame($main, get_exposed_services_cache_key(new Context(workingDirectory: '/home/me/work/app/')));
        static::assertSame($main, get_exposed_services_cache_key(new Context(workingDirectory: '/home/me/work/./app')));
        static::assertMatchesRegularExpression('/^infrastructure\.exposed\.[0-9a-f]{16}$/', $main);
    }
}
