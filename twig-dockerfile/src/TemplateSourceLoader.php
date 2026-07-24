<?php

declare(strict_types=1);

namespace Castor\TwigDockerfile;

use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Twig loader resolving templates through a TemplateSourceInterface.
 *
 * Template names map to build contexts: "Dockerfile" loads from the default
 * "context" build context, "@base/Dockerfile" loads from the additional build
 * context named "base".
 */
final class TemplateSourceLoader implements LoaderInterface
{
    public const ROOT = '.';
    private const DEFAULT_CONTEXT = 'context';

    /** @var array<string, Source> */
    private array $templates = [];

    public function __construct(
        private readonly TemplateSourceInterface $source,
        string $rootContent,
    ) {
        $this->templates[self::ROOT] = new Source($rootContent, self::ROOT);
    }

    public function getSourceContext(string $name): Source
    {
        return $this->loadTemplate($name) ?? throw new LoaderError(\sprintf('Template "%s" is not defined.', $name));
    }

    public function exists(string $name): bool
    {
        return $this->loadTemplate($name) !== null;
    }

    public function getCacheKey(string $name): string
    {
        // the key must depend on the content: Twig caches compiled template
        // classes process-wide, and every transform names its root template "."
        $source = $this->loadTemplate($name) ?? throw new LoaderError(\sprintf('Template "%s" is not defined.', $name));

        return $name . "\0" . hash('xxh128', $source->getCode());
    }

    public function isFresh(string $name, int $time): bool
    {
        return true;
    }

    private function loadTemplate(string $name): ?Source
    {
        if (isset($this->templates[$name])) {
            return $this->templates[$name];
        }

        $context = self::DEFAULT_CONTEXT;
        $filename = $name;

        if (str_starts_with($name, '@')) {
            $parts = explode('/', $name, 2);
            $context = substr($parts[0], 1);
            $filename = $parts[1] ?? '';
        }

        $content = $this->source->load($context, $filename);

        if ($content === null || $content === '') {
            return null;
        }

        return $this->templates[$name] = new Source($content, $name);
    }
}
