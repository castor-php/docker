<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\Ast;
use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\NodeService;
use Castor\Docker\Service\PackageManager;
use Castor\Docker\Service\ServiceInterface;

use function Castor\context;
use function Castor\Docker\docker_compose_run;
use function Castor\fs;
use function Castor\io;

final class NodeInstaller extends AbstractServiceInstaller
{
    /** A plain HTTP server, so "up" serves something without a framework. */
    private const TEMPLATE_NONE = 'none';

    private const TEMPLATE_VITE_REACT = 'vite-react';

    private const TEMPLATE_NEXT = 'next';

    public function getName(): string
    {
        return 'node';
    }

    public function getDescription(): string
    {
        return 'Node.js application (React, Next.js, or a plain server)';
    }

    public function getInputs(): array
    {
        return [
            new Input('name', 'Application name', InputType::Text, 'app'),
            new Input('directory', 'Directory (relative to castor.php)', InputType::Text, static fn(array $answers): string => (string) ($answers['name'] ?? 'app')),
            new Input('version', 'Node.js version', InputType::Text, '24'),
            new Input('package_manager', 'Package manager', InputType::Choice, PackageManager::Npm->value, array_map(static fn(PackageManager $manager): string => $manager->value, PackageManager::cases())),
            new Input('template', 'Application to scaffold', InputType::Choice, self::TEMPLATE_NONE, [self::TEMPLATE_NONE, self::TEMPLATE_VITE_REACT, self::TEMPLATE_NEXT]),
            new Input('port', 'Port the application listens on', InputType::Integer, 3000),
            new Input('domain', 'Domain', InputType::Text, static fn(array $answers): string => \sprintf('%s.%s', $answers['name'] ?? 'app', context()->data['root_domain'] ?? 'castor.local')),
        ];
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $manager = $this->packageManager($answers);

        $expression = $builder->addNewServiceAst(NodeService::class, [(string) $answers['name']])
            ->callMethod('withDirectory', [Ast::raw(\sprintf("__DIR__ . '/%s'", $answers['directory']))])
            ->callMethod('withVersion', [(string) $answers['version']])
            ->callMethod('withPackageManager', [Ast::raw('PackageManager::' . $manager->name)])
            ->callMethod('withPort', [(int) $answers['port']])
        ;
        $builder->addImport(PackageManager::class);

        if (($answers['domain'] ?? '') !== '') {
            $expression->callMethod('withDomain', [(string) $answers['domain']]);
        }
    }

    public function createInstance(array $answers): ServiceInterface
    {
        $service = (new NodeService((string) $answers['name']))
            ->withDirectory(context()->workingDirectory . '/' . $answers['directory'])
            ->withVersion((string) $answers['version'])
            ->withPackageManager($this->packageManager($answers))
            ->withPort((int) $answers['port'])
        ;

        if (($answers['domain'] ?? '') !== '') {
            $service->withDomain((string) $answers['domain']);
        }

        return $service;
    }

    public function prepare(array $answers): void
    {
        fs()->mkdir(context()->workingDirectory . '/' . $answers['directory']);
    }

    public function scaffold(array $answers): void
    {
        $directory = context()->workingDirectory . '/' . $answers['directory'];

        // The container command is a package.json script, so a directory
        // without a package.json would leave it restarting forever. Whatever
        // was already there is left alone.
        if (!is_file($directory . '/package.json')) {
            match ((string) $answers['template']) {
                self::TEMPLATE_VITE_REACT => $this->scaffoldVite($answers, $directory),
                self::TEMPLATE_NEXT => $this->scaffoldNext($answers, $directory),
                default => $this->scaffoldServer($answers, $directory),
            };
        }

        $this->runInService($answers, [$this->packageManager($answers)->value, 'install']);
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function packageManager(array $answers): PackageManager
    {
        return PackageManager::from((string) ($answers['package_manager'] ?? PackageManager::Npm->value));
    }

    /**
     * A dependency-free HTTP server, listening where the service says.
     *
     * @param array<string, mixed> $answers
     */
    private function scaffoldServer(array $answers, string $directory): void
    {
        foreach (['package.json', 'server.js'] as $file) {
            $content = file_get_contents(__DIR__ . '/../Resources/node/skeleton/' . $file);
            \assert($content !== false);

            fs()->dumpFile($directory . '/' . $file, str_replace(
                ['__NAME__', '__PORT__'],
                [(string) $answers['name'], (string) (int) $answers['port']],
                $content,
            ));
        }
    }

    /**
     * The official create-vite, run through npx so the invocation is the same
     * whichever package manager the service uses. It scaffolds but does not
     * install; scaffold() runs the install afterwards.
     *
     * @param array<string, mixed> $answers
     */
    private function scaffoldVite(array $answers, string $directory): void
    {
        $this->runInService($answers, ['npx', '--yes', 'create-vite@latest', '.', '--template', 'react']);

        // create-vite writes a config with no server section at all, which
        // leaves Vite bound to localhost inside the container. Overwriting it is
        // the whole point of scaffolding through this installer.
        $content = file_get_contents(__DIR__ . '/../Resources/node/skeleton/vite.config.js');
        \assert($content !== false);

        $hmr = ($answers['domain'] ?? '') !== ''
            // The page is served over HTTPS on 443 by the router, so the HMR
            // client has to open its websocket there rather than on the port
            // Vite listens on inside the container.
            ? "        hmr: { protocol: 'wss', clientPort: 443 },\n"
            : '';

        fs()->dumpFile($directory . '/vite.config.js', str_replace(
            ['__PORT__', '__HMR__'],
            [(string) (int) $answers['port'], $hmr],
            $content,
        ));

        // create-vite ships its own, which would now win over the one above.
        fs()->remove($directory . '/vite.config.ts');
    }

    /**
     * The official create-next-app. "next dev" already binds 0.0.0.0 and reads
     * the PORT the service sets, so only the cross-origin guard needs a word.
     *
     * @param array<string, mixed> $answers
     */
    private function scaffoldNext(array $answers, string $directory): void
    {
        // Not ".": create-next-app checks the *parent* of its target for
        // writability, and the parent of the /app mount is the root of the
        // container, which the unprivileged user running this does not own — so
        // scaffolding in place fails on a permission the application never
        // needed. Give it a directory to create below the mount instead, and
        // lift the result up.
        $temporary = '.castor-next';

        $this->runInService($answers, [
            'npx', '--yes', 'create-next-app@latest', $temporary, '--yes',
            '--use-' . $this->packageManager($answers)->value,
        ]);

        $this->lift($directory . '/' . $temporary, $directory);

        $this->allowDevOrigin($answers, $directory);
    }

    /**
     * Move everything $source holds into $target, then drop $source. Both sides
     * are on the same filesystem, so each entry is a rename and node_modules
     * costs nothing to move.
     */
    private function lift(string $source, string $target): void
    {
        foreach (scandir($source) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            fs()->rename($source . '/' . $entry, $target . '/' . $entry, true);
        }

        fs()->remove($source);
    }

    /**
     * Next.js refuses the dev requests coming from another origin than the one
     * it listens on, which is exactly what being served on a domain by the
     * router is. The generated config carries a marker comment where the
     * options go; when it does not, say so rather than rewrite blind.
     *
     * @param array<string, mixed> $answers
     */
    private function allowDevOrigin(array $answers, string $directory): void
    {
        $domain = (string) ($answers['domain'] ?? '');

        if ('' === $domain) {
            return;
        }

        $option = \sprintf('allowedDevOrigins: ["%s"],', $domain);

        foreach (['next.config.ts', 'next.config.mjs', 'next.config.js'] as $file) {
            $path = $directory . '/' . $file;

            if (!is_file($path)) {
                continue;
            }

            $content = file_get_contents($path);
            \assert($content !== false);

            if (!str_contains($content, '/* config options here */')) {
                break;
            }

            fs()->dumpFile($path, str_replace('/* config options here */', $option, $content));

            return;
        }

        io()->warning(\sprintf('Could not add "%s" to the Next.js configuration: add it by hand, or "next dev" will reject the requests coming from https://%s.', $option, $domain));
    }

    /**
     * @param array<string, mixed> $answers
     * @param list<string>         $command
     */
    private function runInService(array $answers, array $command): void
    {
        docker_compose_run($command, service: (string) $answers['name'], workDir: '/app');
    }
}
