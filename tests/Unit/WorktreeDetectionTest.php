<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function Castor\Docker\detect_worktree;
use function Castor\Docker\get_project_name;
use function Castor\Docker\initialize_project;
use function Castor\Docker\slugify_worktree_name;

/**
 * A linked worktree is a checkout of its own, and has to be a stack of its own:
 * sharing the compose project with the main checkout means sharing its
 * containers, its volumes and its domains.
 *
 * Detection reads the ".git" of the checkout rather than asking git, because it
 * happens on every single castor boot.
 */
final class WorktreeDetectionTest extends TestCase
{
    private string $root;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $root = tempnam(sys_get_temp_dir(), 'worktree');
        \assert($root !== false);
        unlink($root);

        $this->root = $root;
        $this->fs = new Filesystem();
        $this->fs->mkdir($root . '/app/.git');
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->root);
    }

    /**
     * Writes the ".git" file "git worktree add" leaves in a linked checkout.
     */
    private function linkWorktree(string $path, string $id): string
    {
        $this->fs->mkdir($path);
        $this->fs->dumpFile($path . '/.git', "gitdir: {$this->root}/app/.git/worktrees/{$id}\n");

        return $path;
    }

    public function testTheMainCheckoutIsNotAWorktree(): void
    {
        static::assertNull(detect_worktree($this->root . '/app'));
    }

    public function testADirectoryOutsideOfAnyRepositoryIsNotAWorktree(): void
    {
        $this->fs->mkdir($this->root . '/elsewhere');

        static::assertNull(detect_worktree($this->root . '/elsewhere'));
    }

    public function testALinkedWorktreeIsNamedAfterItsDirectory(): void
    {
        $path = $this->linkWorktree($this->root . '/worktrees/bug-4242', 'bug-4242');

        static::assertSame('bug-4242', detect_worktree($path));
    }

    /**
     * A subdirectory of the checkout answers the same: castor.php does not have
     * to sit at the root of the repository.
     */
    public function testASubdirectoryOfAWorktreeIsTheWorktree(): void
    {
        $path = $this->linkWorktree($this->root . '/worktrees/bug-4242', 'bug-4242');
        $this->fs->mkdir($path . '/infrastructure');

        static::assertSame('bug-4242', detect_worktree($path . '/infrastructure'));
    }

    /**
     * The layout the editors creating worktrees on their own use: git's worktree
     * id would be "app", "app1"… for every one of them, so the name comes from
     * the directory above.
     */
    public function testAWorktreeRepeatingTheRepositoryNameIsNamedAfterItsParent(): void
    {
        $path = $this->linkWorktree($this->root . '/worktrees/bug-4242/app', 'app');

        static::assertSame('bug-4242', detect_worktree($path));
    }

    /**
     * A submodule has a ".git" file too, pointing into ".git/modules".
     */
    public function testASubmoduleIsNotAWorktree(): void
    {
        $this->fs->mkdir($this->root . '/app/lib');
        $this->fs->dumpFile($this->root . '/app/lib/.git', "gitdir: ../.git/modules/lib\n");

        static::assertNull(detect_worktree($this->root . '/app/lib'));
    }

    public function testTheNameIsSlugifiedForAComposeProjectAndADnsLabel(): void
    {
        $path = $this->linkWorktree($this->root . '/worktrees/Feat_Big Thing', 'x');

        static::assertSame('feat-big-thing', detect_worktree($path));
    }

    public function testANameWithNothingUsableInItIsNoName(): void
    {
        static::assertNull(slugify_worktree_name('___'));
        static::assertSame('a', slugify_worktree_name('-a-'));
    }

    /**
     * What the context ends up with: the compose project and the root domain
     * both move, which is what every name of the stack derives from.
     */
    public function testTheContextOfAWorktreeIsIsolated(): void
    {
        $path = $this->linkWorktree($this->root . '/worktrees/bug-4242', 'bug-4242');
        $this->fs->dumpFile($path . '/compose.yaml', "name: app\n");

        $context = initialize_project(new Context(
            data: ['root_domain' => 'myproject.test'],
            workingDirectory: $path,
        ));

        static::assertSame('bug-4242', $context->data['worktree']);
        static::assertSame('app-bug-4242', $context->data['project_name']);
        static::assertSame('app-bug-4242', get_project_name($context));
        static::assertSame('bug-4242.myproject.test', $context->data['root_domain']);
    }

    /**
     * initialize_project() runs on every boot, so the derivation must not pile
     * up — and a project doing the derivation itself must not be doubled up on.
     */
    public function testTheIsolationIsIdempotent(): void
    {
        $path = $this->linkWorktree($this->root . '/worktrees/bug-4242', 'bug-4242');
        $this->fs->dumpFile($path . '/compose.yaml', "name: app\n");

        $context = new Context(
            data: ['root_domain' => 'myproject.test'],
            workingDirectory: $path,
        );

        $context = initialize_project(initialize_project(initialize_project($context)));

        static::assertSame('app-bug-4242', $context->data['project_name']);
        static::assertSame('bug-4242.myproject.test', $context->data['root_domain']);
    }

    public function testAProjectDerivingItsOwnNamesIsLeftAlone(): void
    {
        $path = $this->linkWorktree($this->root . '/worktrees/bug-4242', 'bug-4242');
        $this->fs->dumpFile($path . '/compose.yaml', "name: app\n");

        $context = initialize_project(new Context(
            data: [
                'project_name' => 'app-bug-4242',
                'root_domain' => 'bug-4242.myproject.test',
            ],
            workingDirectory: $path,
        ));

        static::assertSame('app-bug-4242', $context->data['project_name']);
        static::assertSame('bug-4242.myproject.test', $context->data['root_domain']);
    }

    /**
     * Turning the isolation off is how a checkout goes back to acting on the
     * stack of the main one.
     */
    public function testTheIsolationCanBeTurnedOff(): void
    {
        $path = $this->linkWorktree($this->root . '/worktrees/bug-4242', 'bug-4242');
        $this->fs->dumpFile($path . '/compose.yaml', "name: app\n");

        $context = initialize_project(new Context(
            data: ['root_domain' => 'myproject.test', 'worktree_isolation' => false],
            workingDirectory: $path,
        ));

        static::assertNull($context->data['worktree']);
        static::assertSame('app', $context->data['project_name']);
        static::assertSame('myproject.test', $context->data['root_domain']);
    }

    /**
     * A project declaring no root domain still gets one of its own, otherwise
     * every checkout would serve the plugin's services on the same fallback.
     */
    public function testAWorktreeGetsARootDomainEvenWithoutOne(): void
    {
        $path = $this->linkWorktree($this->root . '/worktrees/bug-4242', 'bug-4242');
        $this->fs->dumpFile($path . '/compose.yaml', "name: app\n");

        $context = initialize_project(new Context(workingDirectory: $path));

        static::assertSame('bug-4242.' . \Castor\Docker\DEFAULT_ROOT_DOMAIN, $context->data['root_domain']);
    }

    /**
     * The main checkout keeps exactly what the project declared, down to having
     * no root domain at all.
     */
    public function testTheMainCheckoutIsUntouched(): void
    {
        $this->fs->dumpFile($this->root . '/app/compose.yaml', "name: app\n");

        $context = initialize_project(new Context(workingDirectory: $this->root . '/app'));

        static::assertNull($context->data['worktree']);
        static::assertSame('app', $context->data['project_name']);
        static::assertArrayNotHasKey('root_domain', $context->data);
    }
}
