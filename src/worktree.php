<?php

declare(strict_types=1);

namespace Castor\Docker;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsListener;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\DumpableServiceInterface;
use Castor\Docker\Service\ServiceInterface;
use Castor\Event\AfterBootEvent;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

use function Castor\app;
use function Castor\capture;
use function Castor\context;
use function Castor\fs;
use function Castor\io;
use function Castor\run;
use function Castor\yaml_parse;

/**
 * The root domain a project falls back to when it declares none.
 *
 * Only the worktree code reads it: the rest of the plugin keeps its own
 * fallbacks, which predate this constant.
 */
const DEFAULT_ROOT_DOMAIN = 'castor.local';

/**
 * The ".git" of the checkout a directory belongs to: a directory in a regular
 * clone, a file in a linked worktree or in a submodule.
 *
 * Walked up by hand rather than asked to git: this runs on every castor boot,
 * where a subprocess would be paid for by every single command.
 */
function find_git_path(string $directory): ?string
{
    $directory = rtrim($directory, '/');

    while ('' !== $directory && '/' !== $directory) {
        if (file_exists($directory . '/.git')) {
            return $directory . '/.git';
        }

        $parent = \dirname($directory);

        if ($parent === $directory) {
            return null;
        }

        $directory = $parent;
    }

    return null;
}

/**
 * The linked git worktree a directory is checked out in: its name, and the main
 * checkout it hangs from. Null when the directory belongs to the main checkout.
 *
 * A linked worktree has a ".git" file pointing at ".git/worktrees/<id>" of the
 * main checkout, where the main one has a ".git" directory — which is the whole
 * test. A submodule also has a ".git" file, but it points into ".git/modules".
 *
 * @return array{name: ?string, main: string}|null
 */
function read_worktree_link(string $directory): ?array
{
    $gitPath = find_git_path($directory);

    if (null === $gitPath || !is_file($gitPath)) {
        return null;
    }

    if (!preg_match('#gitdir:\s*(?<gitdir>\S+/worktrees/\S+)#', (string) file_get_contents($gitPath), $matches)) {
        return null;
    }

    $main = rtrim(\dirname($matches['gitdir'], 3), '/');

    return ['name' => worktree_slug(\dirname($gitPath), $main), 'main' => $main];
}

/**
 * The name of the linked git worktree a directory is checked out in, or null
 * when it belongs to the main checkout.
 */
function detect_worktree(string $directory): ?string
{
    return read_worktree_link($directory)['name'] ?? null;
}

/**
 * The name that tells a checkout apart, which is not git's own worktree id: git
 * derives that id from the directory it was given, so a layout nesting the
 * checkouts as "<name>/<repository>" produces "<repository>", "<repository>1"…
 * for every one of them. Hence the rule — the checkout directory, or its parent
 * when the checkout repeats the name of the main one.
 */
function worktree_slug(string $path, string $mainDirectory): ?string
{
    $name = basename($path);

    if ($name === basename($mainDirectory)) {
        $name = basename(\dirname($path));
    }

    return slugify_worktree_name($name);
}

/**
 * What both a compose project name and a DNS label accept.
 */
function slugify_worktree_name(string $name): ?string
{
    $name = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));

    return trim($name, '-') ?: null;
}

/**
 * The worktree this checkout is, or null for the main one.
 *
 * Set on the context by initialize_project(), so a project that pins it — or
 * that turns the isolation off — is answered the same way everywhere.
 */
function get_worktree_name(?Context $c = null): ?string
{
    return ($c ?? context())->data['worktree'] ?? null;
}

/**
 * The root domain of the main checkout: the one the project declares, before a
 * worktree prefixes it with its own label.
 */
function get_worktree_base_domain(?Context $c = null): string
{
    $c ??= context();
    $domain = $c->data['root_domain'] ?? DEFAULT_ROOT_DOMAIN;
    $worktree = get_worktree_name($c);

    if (null !== $worktree && str_starts_with($domain, $worktree . '.')) {
        return substr($domain, \strlen($worktree) + 1);
    }

    return $domain;
}

/**
 * The compose project name of the main checkout, a worktree suffix aside.
 */
function get_base_project_name(?Context $c = null): string
{
    $c ??= context();
    $name = get_project_name($c);
    $worktree = get_worktree_name($c);

    if (null !== $worktree && str_ends_with($name, '-' . $worktree)) {
        return substr($name, 0, -\strlen($worktree) - 1);
    }

    return $name;
}

/**
 * The compose project name, and the root domain, a given checkout runs under.
 */
function worktree_project_name(?string $worktree, string $baseName): string
{
    return null === $worktree ? $baseName : $baseName . '-' . $worktree;
}

function worktree_root_domain(?string $worktree, string $baseDomain): string
{
    return null === $worktree ? $baseDomain : $worktree . '.' . $baseDomain;
}

/**
 * The domain this checkout serves in place of the given one.
 *
 * A worktree answers under a label of its own inserted right before the root
 * domain, so "app.myproject.test" becomes "app.wt2.myproject.test" — the same
 * shape the plugin's own services get for free by deriving their domain from
 * "root_domain".
 *
 * A domain that is neither the root domain nor a subdomain of it is left alone:
 * nothing says what it is supposed to become. Idempotent, so a project already
 * deriving its domains from the (prefixed) root domain is untouched.
 */
function worktree_domain(string $domain, ?Context $c = null): string
{
    $c ??= context();
    $worktree = get_worktree_name($c);

    if (null === $worktree) {
        return $domain;
    }

    $base = get_worktree_base_domain($c);
    $scoped = worktree_root_domain($worktree, $base);

    if ($domain === $scoped || str_ends_with($domain, '.' . $scoped)) {
        return $domain;
    }

    if ($domain === $base) {
        return $scoped;
    }

    if (str_ends_with($domain, '.' . $base)) {
        return substr($domain, 0, -\strlen($base)) . $scoped;
    }

    return $domain;
}

/**
 * Move every routed domain of the project onto the worktree's own subdomain.
 *
 * Applied to the built compose file rather than left to each service: a domain
 * spelled out in a castor.php — "app.myproject.test" — knows nothing about the
 * checkout it is generated in, and would otherwise make two checkouts fight
 * over the same site in the global router.
 */
function apply_worktree_domains(Context $c, ComposeBuilder $composeBuilder): void
{
    if (null === get_worktree_name($c)) {
        return;
    }

    foreach ($composeBuilder->getServices() as $service) {
        $service->rewriteRoutedDomains(static fn(string $domain): string => worktree_domain($domain, $c));
    }
}

/**
 * What a worktree still shares with the main checkout, despite running a stack
 * of its own: the domains that could not be moved under its own subdomain, and
 * the host ports a service publishes statically.
 *
 * Both are machine-wide, so the two stacks cannot have them at the same time —
 * and neither is something the plugin can rename on the project's behalf.
 * Reported by "docker:about" rather than on every run.
 *
 * @return list<string>
 */
function get_worktree_conflicts(?Context $c = null): array
{
    $c ??= context();
    $worktree = get_worktree_name($c);

    if (null === $worktree) {
        return [];
    }

    $scoped = worktree_root_domain($worktree, get_worktree_base_domain($c));
    $conflicts = [];

    foreach (get_project_domains($c) as $domain) {
        if (!is_worktree_domain($domain, $c)) {
            $conflicts[] = \sprintf('the domain "%s", which is not under "%s": the router hands it to whichever container it sees first', $domain, $scoped);
        }
    }

    foreach (get_project_published_ports($c) as $service => $ports) {
        $conflicts[] = \sprintf('the host port(s) %s published by "%s": only one stack at a time can bind them', implode(', ', $ports), $service);
    }

    return $conflicts;
}

/**
 * Whether a domain is this checkout's own: its root domain, or a subdomain of
 * it. Every domain is, in the main checkout.
 */
function is_worktree_domain(string $domain, ?Context $c = null): bool
{
    $c ??= context();
    $worktree = get_worktree_name($c);

    if (null === $worktree) {
        return true;
    }

    $scoped = worktree_root_domain($worktree, get_worktree_base_domain($c));

    return $domain === $scoped || str_ends_with($domain, '.' . $scoped);
}

/**
 * The host ports the project publishes statically, keyed by compose service.
 *
 * Read from the compose files, the ones the project writes itself included: a
 * "ports:" in compose.override.yaml collides just as much.
 *
 * @return array<string, list<string>>
 */
function get_project_published_ports(?Context $c = null): array
{
    $c ??= context();
    $ports = [];

    foreach (['compose.generated.yaml', 'compose.yaml', 'compose.override.yaml'] as $file) {
        $path = $c->workingDirectory . '/' . $file;

        if (!file_exists($path) || !($content = file_get_contents($path))) {
            continue;
        }

        $compose = yaml_parse($content);

        foreach ($compose['services'] ?? [] as $name => $service) {
            foreach ($service['ports'] ?? [] as $port) {
                $published = \is_array($port) ? ($port['published'] ?? null) : explode(':', (string) $port)[0];

                if (null === $published || '' === $published) {
                    continue;
                }

                $ports[(string) $name][] = (string) $published;
            }
        }
    }

    return array_map('array_values', array_map('array_unique', $ports));
}

/**
 * "--worktree <name>" on any task: castor re-runs itself in that checkout, so
 * the task acts on its stack, with its code and its dependencies.
 *
 * Registered on every command at once rather than declared task by task, and
 * read from the raw arguments because the boot happens before the input is
 * parsed.
 */
#[AsListener(event: AfterBootEvent::class)]
function run_task_in_worktree(AfterBootEvent $event): void
{
    foreach ($event->application->all() as $command) {
        // A project may declare the option itself, and declaring it twice throws.
        if (!$command->getDefinition()->hasOption('worktree')) {
            $command->addOption('worktree', null, InputOption::VALUE_REQUIRED, 'Run the task in this worktree ("main" for the main checkout) instead of the current checkout', null, autocomplete_worktree_target(...));
        }
    }

    /** @var list<string> $argv */
    $argv = $_SERVER['argv'] ?? [];
    [$name, $arguments] = extract_worktree_option(\array_slice($argv, 1));

    if (null === $name) {
        return;
    }

    $path = 'main' === $name ? get_main_checkout_directory() : (find_worktree($name)['path'] ?? null);

    if (null === $path) {
        io()->error(\sprintf('Unknown worktree "%s". Run "castor worktree:list" to see them.', $name));

        exit(1);
    }

    // Already there — castor.php may sit in a subdirectory of the checkout.
    if (Path::isBasePath(Path::canonicalize($path), Path::canonicalize(context()->workingDirectory))) {
        return;
    }

    exit(run([castor_binary(), ...$arguments], context: in_worktree($path)->withAllowFailure())->getExitCode());
}

#[AsTask(name: 'list', namespace: 'worktree', description: 'Lists the checkouts of this repository and the state of their stack', aliases: ['worktrees'])]
function worktree_list(): void
{
    $c = context();
    $baseName = get_base_project_name($c);
    $baseDomain = get_worktree_base_domain($c);
    $stacks = get_compose_stacks();
    $rows = [];

    foreach (get_checkouts() as ['name' => $name, 'path' => $path, 'branch' => $branch]) {
        $rows[] = [
            $name ?? '<info>(main)</>',
            $branch,
            worktree_project_name($name, $baseName),
            $stacks[$path] ?? '<fg=gray>not created</>',
            \sprintf('<href=https://%1$s>https://%1$s</>', worktree_root_domain($name, $baseDomain)),
        ];
    }

    io()->table(['Worktree', 'Branch', 'Compose project', 'Stack', 'URL'], $rows);
    io()->comment('Run any task in another checkout with <comment>castor --worktree=<name> <task></comment>.');
}

#[AsTask(name: 'create', namespace: 'worktree', description: 'Creates a worktree, which is a stack of its own')]
function worktree_create(
    #[AsArgument(description: 'Name of the worktree: its directory, its compose project and its subdomain')]
    string $name,
    #[AsOption(description: 'Branch to check out, created when it does not exist yet (defaults to the name of the worktree)', autocomplete: 'Castor\Docker\autocomplete_branch_name')]
    ?string $branch = null,
    #[AsOption(description: 'Where a branch that does not exist yet starts from')]
    string $from = 'HEAD',
    #[AsOption(description: 'Build and start the stack of the worktree once it is created')]
    bool $start = false,
    #[AsOption(description: 'Copy the databases of this checkout into those of the worktree')]
    bool $copyData = false,
): void {
    $slug = slugify_worktree_name($name);

    if (null === $slug) {
        io()->error(\sprintf('"%s" is not a usable worktree name.', $name));

        return;
    }

    $main = get_main_checkout_directory();
    $path = get_worktree_path($slug);

    if (is_dir($path)) {
        io()->error(\sprintf('The worktree "%s" already exists (%s).', $slug, $path));

        return;
    }

    // The name as it was typed: "feat/new-thing" is a fine branch, where only
    // the directory, the compose project and the subdomain need the slug.
    $branch ??= $name;
    $exists = '' !== capture(['git', '-C', $main, 'rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch], onFailure: '');

    io()->section(\sprintf('Creating the worktree "%s"', $slug));
    run($exists
        ? ['git', '-C', $main, 'worktree', 'add', $path, $branch]
        : ['git', '-C', $main, 'worktree', 'add', $path, '-b', $branch, $from]);

    $c = context();
    $domain = worktree_root_domain($slug, get_worktree_base_domain($c));

    if ($copyData) {
        $databases = array_values(array_filter(collect_services(), static fn(ServiceInterface $service): bool => $service instanceof DumpableServiceInterface));

        if ($databases) {
            io()->section('Copying the databases of this checkout');
            copy_databases_to_worktree($databases, $path);
        } else {
            io()->comment('There is no database to copy.');
        }
    }

    if ($start) {
        io()->section('Starting its stack');
        run([castor_binary(), ...worktree_start_task()], context: in_worktree($path));
    }

    io()->success(\sprintf('Worktree "%s" created in %s.', $slug, $path));

    if ($start) {
        io()->comment('It answers on https://' . $domain);
    } else {
        io()->comment(\sprintf('Start its stack with: cd %s && castor %s', $path, implode(' ', worktree_start_task())));
    }
}

#[AsTask(name: 'delete', namespace: 'worktree', description: 'Deletes a worktree and the stack that goes with it')]
function worktree_delete(
    #[AsArgument(description: 'Name of the worktree, or the branch checked out in it', autocomplete: 'Castor\Docker\autocomplete_worktree_name')]
    string $name,
    #[AsOption(description: 'Skip the confirmations (uncommitted changes, unpushed commits)', shortcut: 'f')]
    bool $force = false,
): void {
    $checkout = find_worktree($name);

    if (null === $checkout) {
        io()->error(\sprintf('Unknown worktree "%s". Run "castor worktree:list" to see them.', $name));

        return;
    }

    ['name' => $slug, 'path' => $path] = $checkout;

    if (Path::isBasePath(Path::canonicalize($path), Path::canonicalize((string) getcwd()))) {
        io()->error(\sprintf('You are inside "%s": run the deletion from another checkout.', $slug));

        return;
    }

    if (!$force && !is_worktree_disposable($path, $checkout['branch'])) {
        io()->comment('Aborted.');

        return;
    }

    $bestEffort = context()->withAllowFailure();
    $main = get_main_checkout_directory();

    io()->section(\sprintf('Destroying the stack of "%s"', $slug));
    run([castor_binary(), 'docker:destroy', '--force'], context: in_worktree($path)->withAllowFailure());

    io()->section(\sprintf('Removing the worktree "%s"', $slug));
    run(['git', '-C', $main, 'worktree', 'remove', '--force', $path], context: $bestEffort);
    run(['git', '-C', $main, 'worktree', 'prune'], context: $bestEffort);

    // The containers write into the checkout (caches, build artifacts, the
    // shared home directory), which can make "git worktree remove" leave
    // debris behind.
    if (is_dir($path)) {
        fs()->remove($path);
    }

    $parent = \dirname($path);

    if (is_dir($parent) && [] === array_diff((array) scandir($parent), ['.', '..'])) {
        rmdir($parent);
    }

    io()->success(\sprintf('Worktree "%s" deleted, its branch is kept.', $slug));
}

/**
 * Every checkout of the repository, the main one first, named the way its
 * compose project and its domain are.
 *
 * @return list<array{name: ?string, path: string, branch: string}>
 */
function get_checkouts(): array
{
    $main = get_main_checkout_directory();
    $checkouts = [];

    // One paragraph per checkout, "worktree <path>" first and "branch <ref>"
    // only when the HEAD is not detached.
    foreach (explode("\n\n", trim(capture(['git', '-C', $main, 'worktree', 'list', '--porcelain'], onFailure: ''))) as $entry) {
        if (!preg_match('{^worktree (?<path>.+)$}m', $entry, $matches)) {
            continue;
        }

        $path = rtrim($matches['path'], '/');
        $checkouts[] = [
            'name' => $path === $main ? null : worktree_slug($path, $main),
            'path' => $path,
            'branch' => preg_match('{^branch refs/heads/(?<branch>.+)$}m', $entry, $branch) ? $branch['branch'] : '(detached)',
        ];
    }

    return $checkouts;
}

/**
 * The checkout a name stands for: its worktree name, or the branch checked out
 * in it.
 *
 * @return array{name: ?string, path: string, branch: string}|null
 */
function find_worktree(string $name): ?array
{
    $slug = slugify_worktree_name($name);

    foreach (get_checkouts() as $checkout) {
        if (null !== $checkout['name'] && ($slug === $checkout['name'] || $name === $checkout['branch'])) {
            return $checkout;
        }
    }

    return null;
}

/**
 * The directory of the main checkout, the one the linked worktrees hang from.
 */
function get_main_checkout_directory(?Context $c = null): string
{
    $c ??= context();

    // A worktree carries the answer in its ".git" file, which is what keeps this
    // out of a subprocess on every boot — the shared home directory asks for it.
    if (null !== ($link = read_worktree_link($c->workingDirectory))) {
        return $link['main'];
    }

    $common = capture(['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'], context: $c->withAllowFailure(), onFailure: '');

    if ('' === $common) {
        return $c->workingDirectory;
    }

    return rtrim(\dirname($common), '/');
}

/**
 * Where a service's shared home directory really lives.
 *
 * Bind-mounted from the checkout it is declared in, every worktree would get an
 * empty Composer, Cargo and npm cache, and pay for a cold build of its own. A
 * worktree therefore mounts the one of the main checkout, by absolute path, so
 * the caches are filled once for the whole repository.
 *
 * A directory the project already made absolute is left alone, and so is every
 * checkout when "worktree_shared_home" is turned off.
 */
function shared_home_directory(string $directory, ?Context $c = null): string
{
    $c ??= context();

    if (null === get_worktree_name($c) || str_starts_with($directory, '/') || false === ($c->data['worktree_shared_home'] ?? true)) {
        return $directory;
    }

    return Path::makeAbsolute($directory, get_main_checkout_directory($c));
}

/**
 * Where a worktree is checked out.
 *
 * "<parent of the main checkout>/worktrees/<repository>/<name>" by default,
 * which keeps the checkouts out of the main one and out of the way of the
 * editors. The "worktree_directory" context data overrides it: a relative path
 * is resolved against the parent of the main checkout, and a "{name}"
 * placeholder is where the name of the worktree goes — "worktrees/{name}/app"
 * for a layout repeating the repository name inside each worktree.
 */
function get_worktree_path(string $slug, ?Context $c = null): string
{
    return resolve_worktree_path($slug, get_main_checkout_directory(), ($c ?? context())->data['worktree_directory'] ?? null);
}

function resolve_worktree_path(string $slug, string $mainDirectory, ?string $pattern): string
{
    $mainDirectory = rtrim($mainDirectory, '/');
    $pattern ??= 'worktrees/' . basename($mainDirectory) . '/{name}';

    if (!str_contains($pattern, '{name}')) {
        $pattern .= '/{name}';
    }

    $path = str_replace('{name}', $slug, $pattern);

    return str_starts_with($path, '/') ? $path : \dirname($mainDirectory) . '/' . $path;
}

/**
 * The status of every compose stack of the machine, keyed by the directory it
 * lives in.
 *
 * @return array<string, string>
 */
function get_compose_stacks(): array
{
    $stacks = [];
    $json = json_decode(capture(['docker', 'compose', 'ls', '--all', '--format', 'json'], context: context()->withAllowFailure(), onFailure: '[]'), true);

    foreach (\is_array($json) ? $json : [] as $stack) {
        if (!\is_array($stack) || !isset($stack['ConfigFiles'], $stack['Status'])) {
            continue;
        }

        $stacks[\dirname(explode(',', (string) $stack['ConfigFiles'])[0])] = (string) $stack['Status'];
    }

    return $stacks;
}

/**
 * What brings a freshly created worktree up: the project's own "start" task when
 * it has one, and the plugin's build-and-up otherwise.
 *
 * @return list<string>
 */
function worktree_start_task(): array
{
    return app()->has('start') ? ['start'] : ['docker:up', '--build'];
}

/**
 * Castor, run in another checkout: it reads its own castor.php there, so the
 * compose project and the domains are the ones of that worktree.
 */
function in_worktree(string $path): Context
{
    $c = context()->withWorkingDirectory($path)->withTimeout(null);

    return Process::isTtySupported() ? $c->withTty() : $c;
}

/**
 * Whether the work in a checkout can be thrown away: uncommitted changes and
 * commits that were never pushed each need an explicit yes.
 */
function is_worktree_disposable(string $path, string $branch): bool
{
    $dirty = capture(['git', '-C', $path, 'status', '--porcelain'], onFailure: '');

    if ('' !== $dirty && !io()->confirm(\sprintf('The worktree has %d uncommitted change(s) that will be LOST. Delete anyway?', \count(explode("\n", $dirty))), false)) {
        return false;
    }

    $upstream = capture(['git', '-C', $path, 'rev-parse', '--abbrev-ref', '@{upstream}'], onFailure: '');

    if ('' === $upstream) {
        return io()->confirm(\sprintf('The branch "%s" was never pushed. Delete the worktree anyway?', $branch), false);
    }

    $ahead = (int) capture(['git', '-C', $path, 'rev-list', '--count', '@{upstream}..HEAD'], onFailure: '0');

    return 0 === $ahead || io()->confirm(\sprintf('The branch "%s" has %d commit(s) not pushed to "%s". Delete anyway?', $branch, $ahead, $upstream), false);
}

/**
 * The "--worktree <name>" (or "--worktree=<name>") the application answers to,
 * and the arguments to forward without it. Everything after "--" belongs to the
 * task.
 *
 * @param list<string> $arguments
 *
 * @return array{0: ?string, 1: list<string>}
 */
function extract_worktree_option(array $arguments): array
{
    $name = null;
    $forwarded = [];
    $isValue = false;
    $isRaw = false;

    foreach ($arguments as $argument) {
        if ($isValue) {
            $name = $argument;
            $isValue = false;
        } elseif ($isRaw) {
            $forwarded[] = $argument;
        } elseif ('--worktree' === $argument) {
            $isValue = true;
        } elseif (str_starts_with($argument, '--worktree=')) {
            $name = substr($argument, \strlen('--worktree='));
        } else {
            $isRaw = '--' === $argument;
            $forwarded[] = $argument;
        }
    }

    return [$name, $forwarded];
}

function castor_binary(): string
{
    return \Phar::running(false) ?: 'castor';
}

/**
 * @return list<string>
 */
function autocomplete_worktree_name(CompletionInput $input): array
{
    return array_values(array_filter(array_column(get_checkouts(), 'name')));
}

/**
 * @return list<string>
 */
function autocomplete_worktree_target(CompletionInput $input): array
{
    return [...autocomplete_worktree_name($input), 'main'];
}

/**
 * @return list<string>
 */
function autocomplete_branch_name(CompletionInput $input): array
{
    $branches = capture(['git', 'branch', '--format', '%(refname:short)'], onFailure: '');

    return '' === $branches ? [] : explode("\n", $branches);
}
