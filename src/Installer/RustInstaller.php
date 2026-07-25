<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\Ast;
use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\RustService;
use Castor\Docker\Service\ServiceInterface;

use function Castor\context;
use function Castor\Docker\docker_compose_run;
use function Castor\fs;

final class RustInstaller extends AbstractServiceInstaller
{
    public function getName(): string
    {
        return 'rust';
    }

    public function getDescription(): string
    {
        return 'Rust application (cargo)';
    }

    public function getInputs(): array
    {
        return [
            new Input('name', 'Application name', InputType::Text, 'app'),
            new Input('directory', 'Directory (relative to castor.php)', InputType::Text, static fn(array $answers): string => (string) ($answers['name'] ?? 'app')),
            new Input('version', 'Rust version', InputType::Text, '1'),
            new Input('port', 'Port the application listens on', InputType::Integer, 8080),
            new Input('domain', 'Domain', InputType::Text, static fn(array $answers): string => \sprintf('%s.%s', $answers['name'] ?? 'app', context()->data['root_domain'] ?? 'castor.local')),
        ];
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $expression = $builder->addNewServiceAst(RustService::class, [
            'name' => (string) $answers['name'],
            'version' => (string) $answers['version'],
            'directory' => Ast::raw(\sprintf("__DIR__ . '/%s'", $answers['directory'])),
            'port' => (int) $answers['port'],
        ]);

        if (($answers['domain'] ?? '') !== '') {
            $expression->callMethod('addDomain', [(string) $answers['domain']]);
        }
    }

    public function createInstance(array $answers): ServiceInterface
    {
        $service = new RustService(
            name: (string) $answers['name'],
            version: (string) $answers['version'],
            directory: context()->workingDirectory . '/' . $answers['directory'],
            port: (int) $answers['port'],
        );

        if (($answers['domain'] ?? '') !== '') {
            $service->addDomain((string) $answers['domain']);
        }

        return $service;
    }

    public function prepare(array $answers): void
    {
        fs()->mkdir(context()->workingDirectory . '/' . $answers['directory']);
    }

    public function scaffold(array $answers): void
    {
        $name = (string) $answers['name'];
        $directory = context()->workingDirectory . '/' . $answers['directory'];

        // The container command is the compiled binary, so a brand new project
        // needs both a crate and a first build before "up" can start it.
        if (!is_file($directory . '/Cargo.toml')) {
            docker_compose_run(\sprintf('cargo init --name %s', $name), service: $name, workDir: '/app');

            $skeleton = file_get_contents(__DIR__ . '/../Resources/rust/skeleton/main.rs');
            \assert($skeleton !== false);

            fs()->dumpFile($directory . '/src/main.rs', str_replace('0.0.0.0:8080', '0.0.0.0:' . (int) $answers['port'], $skeleton));
        }

        docker_compose_run('cargo build', service: $name, workDir: '/app');
    }
}
