<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Castor\Docker\task_name;

/**
 * The messages name the tasks to run: before castor resolves them, as in a
 * test, the name comes from their attribute.
 */
final class TaskNameTest extends TestCase
{
    public function testTheNameComesFromTheAttribute(): void
    {
        static::assertSame('docker:up', task_name('Castor\Docker\up'));
        static::assertSame('docker:router:enable', task_name('Castor\Docker\router_enable'));
        static::assertSame('worktree:list', task_name('Castor\Docker\worktree_list'));
    }

    public function testAFunctionThatIsNoTaskIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        task_name('Castor\Docker\get_project_name');
    }
}
