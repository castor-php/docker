---
title: Git worktrees
description: Every checkout of your repository is a stack of its own, with its own containers, volumes and domains.
---

# Git worktrees

A `git worktree` is a second checkout of the same repository, on another branch,
in another directory. The plugin makes it a **stack of its own**: its own
containers, its own volumes, its own domains — so you can review a pull request,
or reproduce a bug on a release branch, without stopping what you were working
on.

Nothing has to be configured. Add a worktree and its stack is already isolated:

```console
$ castor worktree:create bug-4242
$ cd ../worktrees/myproject/bug-4242 && castor docker:up --build
```

## What a worktree gets of its own

Everything the plugin names is derived from two values, and a linked worktree
gets its own:

| | Main checkout | Worktree `bug-4242` |
|---|---|---|
| `project_name` | `myproject` | `myproject-bug-4242` |
| `root_domain` | `myproject.test` | `bug-4242.myproject.test` |

From there, the containers, the network, the named volumes, the images of a
shared builder and the TCP forwarders of `<service>:expose` all follow.

The domains follow too, **including the ones you spell out yourself**: a service
declaring `withDomain('app.myproject.test')` is served on
`https://app.bug-4242.myproject.test` in the worktree. The worktree's label is
inserted right before the root domain, so a domain already derived from
`root_domain` lands on the very same name — the rewrite is idempotent.

A domain that is not under the root domain is left alone, because nothing says
what it should become. `castor docker:about` reports it, along with any host port
a service publishes with `port()`: those belong to the machine, and only one
checkout at a time can have them.

## The caches are shared

The shared home directory holds what every service of the project caches —
Composer, Cargo, npm. A worktree mounts the **`.home` of the main checkout**, by
absolute path, so the caches are filled once for the whole repository instead of
once per branch: a new worktree does not pay for a cold build.

It is created from the worktree when the main checkout never ran castor, so a
fresh clone works either way round. Set `worktree_shared_home` to `false` to give
each checkout a `.home` of its own, and a service pointing
`withSharedHomeDirectory()` at an absolute path already keeps whatever it names.

## Managing the checkouts

```console
$ castor worktree:list                       # every checkout, its branch, its stack and its URL
$ castor worktree:create bug-4242            # create it, on a branch of the same name
$ castor worktree:create bug-4242 --start    # …and build and start its stack
$ castor worktree:delete bug-4242            # destroy its stack and remove it, keeping the branch
```

`worktree:create` takes the name as typed for the branch and slugifies it for
everything else, so `castor worktree:create feat/new-thing` checks out
`feat/new-thing` in a `feat-new-thing` worktree. `--branch` picks another one,
and `--from` says where a branch that does not exist yet starts.

`--start` runs your project's own `start` task when it has one, and
`castor docker:up --build` otherwise.

`worktree:delete` asks before throwing away uncommitted changes or commits that
were never pushed; `--force` skips the questions. The branch is always kept.

### Where the checkouts live

`<parent of the main checkout>/worktrees/<repository>/<name>` by default, which
keeps them out of the main checkout. The `worktree_directory` context variable
overrides it:

```php
return new Context([
    // "<parent>/branches/bug-4242"
    'worktree_directory' => 'branches',
    // Or, for the layout some editors create, "<parent>/worktrees/bug-4242/myproject"
    'worktree_directory' => 'worktrees/{name}/myproject',
]);
```

A relative path is resolved against the parent of the main checkout, and `{name}`
is where the name of the worktree goes — appended when you leave it out.

## Running a task somewhere else

Every task takes a `--worktree`, and castor re-runs itself in that checkout — so
the task acts on its stack, with its code and its dependencies:

```console
$ castor --worktree=bug-4242 docker:logs app
$ castor --worktree=main docker:about
```

Beware that a bare `docker compose` command run from a worktree targets the main
stack: `compose.yaml` is shared, `name:` included, and only castor overrides it
(with `COMPOSE_PROJECT_NAME`). Go through `castor docker:*`, or pass
`-p myproject-bug-4242` yourself.

## Turning the isolation off

A checkout that should act on the main stack rather than on one of its own sets:

```php
return new Context([
    'worktree_isolation' => false,
]);
```

The plugin then leaves `project_name` and `root_domain` exactly as you declared
them.

A project that would rather derive the names itself can also just do it: the
plugin only suffixes a project name that does not end with the worktree's name,
and only prefixes a root domain that does not start with it.

## A second clone is not a worktree

Detection reads the `.git` of the checkout: a linked worktree has a `.git` *file*
pointing into `.git/worktrees/`, where a clone has a `.git` directory. A second
clone therefore shares the stack of the first, and has to be told apart by hand —
see [running two checkouts side by side](../configuration.md#running-two-checkouts-side-by-side).
