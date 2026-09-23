<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\RustFSService;
use Castor\Docker\Service\ServiceInterface;

final class RustFSInstaller extends AbstractServiceInstaller
{
    public function getName(): string
    {
        return 'rustfs';
    }

    public function getDescription(): string
    {
        return 'RustFS S3-compatible object storage with its console';
    }

    public function getInputs(): array
    {
        return [
            new Input('version', 'RustFS version (image tag)', InputType::Text, '1.0.0'),
            // Free text rather than a choice: a bucket name is the project's.
            new Input('buckets', 'Buckets to create, comma-separated (empty for none)', InputType::Text, ''),
        ];
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $expression = $builder->addNewServiceAst(RustFSService::class)
            ->callMethod('withVersion', [(string) $answers['version']]);

        foreach (self::parseBuckets($answers) as $bucket) {
            $expression->callMethod('withBucket', [$bucket]);
        }
    }

    public function createInstance(array $answers): ServiceInterface
    {
        $service = (new RustFSService())->withVersion((string) $answers['version']);

        foreach (self::parseBuckets($answers) as $bucket) {
            $service->withBucket($bucket);
        }

        return $service;
    }

    /**
     * @param array<string, mixed> $answers
     *
     * @return list<string>
     */
    private static function parseBuckets(array $answers): array
    {
        $buckets = \is_string($answers['buckets'] ?? null) ? explode(',', $answers['buckets']) : [];

        return array_values(array_unique(array_filter(array_map('trim', $buckets), static fn(string $bucket): bool => '' !== $bucket)));
    }
}
