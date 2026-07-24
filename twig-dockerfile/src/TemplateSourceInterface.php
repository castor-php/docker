<?php

declare(strict_types=1);

namespace Castor\TwigDockerfile;

interface TemplateSourceInterface
{
    /**
     * Returns the file content, or null if it does not exist in that context.
     */
    public function load(string $context, string $filename): ?string;
}
