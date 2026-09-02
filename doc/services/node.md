---
title: Node.js
description: Run a React, Next.js or plain Node application — no PHP in the container.
---

# Node.js

`NodeService` runs a Node.js application from the source directory mounted in
the container. No PHP anywhere: this is the official `node` image, the package
manager of your choice through corepack, and a `package.json` script as the
container command.

The container runs `<manager> run dev` by default, which is exactly what serves
a Vite/React or Next.js development server with its own hot reload — the process
watches the mounted sources itself, so nothing on the host rebuilds anything.

```php
(new NodeService('front'))
    ->withVersion('24')                     // tag of the official node image (default: 24)
    ->withDirectory(__DIR__ . '/front')
    ->withPackageManager(PackageManager::Pnpm)  // npm (default), Yarn or Pnpm
    ->withPort(3000)                        // port the dev server listens on (default: 3000)
    ->withDomain('front.project.test')
    ->withHttpAccess()                      // also serve plain HTTP, without redirecting to HTTPS
```

`castor docker:service:install node` writes that block for you, and scaffolds
the application — see [Scaffolding](#scaffolding-a-new-application).

## Listening on 0.0.0.0

A dev server bound to `localhost` answers only inside its own container, and the
router in front of it returns a 502. This is the one thing that catches
everybody.

The service sets `HOST=0.0.0.0` and `PORT=<port>` in the container, which
Next.js, Nuxt and `react-scripts` read. **Vite reads neither** — tell it in
`vite.config.js`:

```js
export default defineConfig({
    server: {
        host: '0.0.0.0',
        port: 3000,
        // The page is served over HTTPS on 443 by the router, so the HMR client
        // has to open its websocket there and not on the port Vite listens on.
        hmr: { protocol: 'wss', clientPort: 443 },
    },
})
```

Next.js needs one line of its own: it rejects dev requests coming from another
origin than the one it listens on, which is what being served on a domain is.

```js
// next.config.mjs
export default { allowedDevOrigins: ['front.project.test'] }
```

## The package manager

```php
->withPackageManager(PackageManager::Pnpm)
```

Corepack is enabled in the image whichever one you pick, so a project pinning
`"packageManager": "pnpm@10.4.1"` in its `package.json` gets **that** version
regardless — this setting only decides what the generated commands type, and
what the passthrough task is called (`front:pnpm` rather than `front:npm`).

Corepack downloads the manager on first use into the shared home directory
(`COREPACK_HOME=/home/app/.cache/node/corepack`), so it is fetched once for the
whole project and survives a rebuild of the image.

## What the container runs

```php
->withScript('start')                              // "<manager> run start" instead of "run dev"
->withRunCommand(['node', 'server.js'])            // replaces the command, argument vector
->withRunCommand('npm run dev -- --host 0.0.0.0')  // replaces the command, through a shell
->withInstallCommand('npm ci')                     // replaces "<manager> install"
->withBuildCommand('npm run build:prod')           // replaces "<manager> run build"
```

## Paths

Two settings, which only coincide in the simple case:

| Method | What it sets | Default |
|---|---|---|
| `withDirectory()` | the host directory mounted at `/app` | `.` |
| `withWorkingDirectory()` | where the `package.json` lives, **relative to the mount** | `.` |

They come apart as soon as the mount is bigger than the package — a monorepo
whose front-end sits below the root it mounts:

```php
(new NodeService('front'))
    ->withDirectory(__DIR__)            // mount the repository
    ->withWorkingDirectory('apps/front')
```

## When nothing reloads

```php
->withPolling()
```

A bind mount does not carry inotify events across the virtual machine of Docker
Desktop, nor across a Windows filesystem mounted into WSL: the dev server
starts, serves, and then simply never notices an edit. `withPolling()` sets
`CHOKIDAR_USEPOLLING` (Vite, Nuxt) and `WATCHPACK_POLLING` (Next.js, webpack),
which costs CPU — which is why it is opt-in rather than the default.

## Environment

```php
->withEnvironment('API_URL', 'https://api.project.test')
->withEnvironment('SENTRY_DSN')     // no value: passed through from your own environment
```

Anything set here wins over the defaults the service sets, so
`->withEnvironment('PORT', '4000')` is how you override one of them.

## Behind the redirection.io agent

Declare the domain on the [agent](redirectionio.md) rather than on the
application, and leave the service without one:

```php
$front = (new NodeService('front'))
    ->withDirectory(__DIR__ . '/front')
    ->withPort(3000)
;
$event->addService($front);

$event->addService(
    (new RedirectionioAgentService())
        ->addReverseProxy('front.project.test', $front, 'your-project-key')
);
```

The port comes from the service, so there is nothing to repeat — passing the
service *name* instead of the instance still needs `port: 3000`.

## Generated tasks

* `castor front:install` — install the dependencies (`<manager> install`)
* `castor front:build` — build the application (`<manager> run build`)
* `castor front:restart` — restart the service
* `castor front:watch` — restart on every change to a `.js`, `.ts`, `.jsx`,
  `.tsx` or `.json` file. For a plain `node server.js`; a dev server watches by
  itself and wants nothing of this
* `castor front:npm` — any command of the package manager, named after it
  (`front:pnpm`, `front:yarn`)
* `castor front:bash` — a bash shell in the container

## Containers

* `front` — the Node application, sources mounted at `/app`, shared home
  directory at `/home/app`

`node_modules` lives in your sources through the mount, like every other build
artifact of the plugin: it survives container recreation, and the editor on the
host resolves imports. Install it **from the container**
(`castor front:install`) — a `npm install` run on a macOS or Windows host writes
native binaries the Linux container cannot execute.

## Scaffolding a new application

`castor docker:service:install node` asks for a template and creates the
application before the first `up`, so the container serves something as soon as
the install finishes:

| Template | What it runs |
|---|---|
| `none` | a dependency-free `node --watch server.js`, listening on the port you chose |
| `vite-react` | `create-vite --template react`, plus the `vite.config.js` shown above |
| `next` | `create-next-app`, plus the `allowedDevOrigins` line |

## Dockerfile extension points

`NodeService` is built from a [Twig
Dockerfile](../going-further/custom-dockerfile.md) you can extend:

```dockerfile
# syntax=ghcr.io/castor-php/twig-dockerfile:0.1
{% extends 'Dockerfile' %}

{% block node_base %}
    {{ parent() }}
RUN apt-get update \
    && apt-get install -y --no-install-recommends imagemagick \
    && rm -rf /var/lib/apt/lists/*
{% endblock %}
```

Then `->withDockerfile(__DIR__ . '/Dockerfile')`. This is also how you install
extra Debian packages — there is no `addAptPackage()`, the block is the
extension point.

### Blocks

| Block | Stage | What it holds |
|-------|-------|---------------|
| `node_base` | `node-base` | the official `node` image, corepack, `WORKDIR /app` |
| `runtime` | `runtime` | `FROM node-base`, the stage the container runs |

### Variables

| Variable | Type | Comes from |
|----------|------|------------|
| `package_manager` | `npm`, `yarn` or `pnpm` | `withPackageManager()` |

The version is **not** a Twig variable: it reaches `FROM` through the
`node_version` Docker build argument and shell interpolation. Build arguments
are JSON-decoded before Twig sees them, which would turn `"20.10"` into the
number `20.1` and pull the wrong image.
