<?php

declare(strict_types=1);

namespace Castor\TwigDockerfile;

final class ArrayTemplateSource implements TemplateSourceInterface
{
    /**
     * @param array<string, array<string, string>> $files indexed by context name, then filename
     */
    public function __construct(
        private readonly array $files = [],
    ) {}

    public function load(string $context, string $filename): ?string
    {
        return $this->files[$context][$filename] ?? null;
    }
}
