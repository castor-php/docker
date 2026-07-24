<?php

declare(strict_types=1);

namespace Castor\TwigDockerfile;

use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\TwigFunction;

final class DockerfileTransformer
{
    public function __construct(
        private readonly TemplateSourceInterface $source,
    ) {}

    /**
     * @param array<string, mixed> $args build args exposed as Twig variables
     */
    public function transform(string $dockerfile, array $args): string
    {
        $loader = new TemplateSourceLoader($this->source, self::stripLeadingComments($dockerfile));

        $twig = new Environment($loader, [
            'debug' => true,
            'cache' => false,
            // Dockerfiles are not HTML: escaping would corrupt values like `a && b`
            'autoescape' => false,
        ]);
        $twig->addExtension(new DebugExtension());
        $twig->addFunction(new TwigFunction('copy', function (string $template, string $target, string $heredoc = 'EOF') use ($twig, $args): string {
            $content = $twig->render($template, $args);

            return "COPY <<'{$heredoc}' {$target}\n{$content}\n{$heredoc}\n";
        }));

        return $twig->render(TemplateSourceLoader::ROOT, $args);
    }

    /**
     * Extracts build args from the frontend build options ("build-arg:" prefixed
     * entries). JSON values are decoded so complex args can be passed to Twig.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function buildArgsFromOptions(array $options): array
    {
        $args = [];

        foreach ($options as $key => $value) {
            if (!str_starts_with($key, 'build-arg:')) {
                continue;
            }

            $argKey = substr($key, \strlen('build-arg:'));

            if (\is_string($value)) {
                try {
                    $value = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    // not json, keep the original value
                }
            }

            $args[$argKey] = $value;
        }

        return $args;
    }

    /**
     * Removes the leading comment block (the "# syntax=..." directive and any
     * comment directly following it) so the upstream frontend does not process
     * the syntax line again. Comments after the first non-comment line are
     * preserved, including inside heredocs.
     */
    public static function stripLeadingComments(string $dockerfile): string
    {
        $lines = explode("\n", $dockerfile);
        $offset = 0;

        while ($offset < \count($lines) && str_starts_with(trim($lines[$offset]), '#')) {
            ++$offset;
        }

        return implode("\n", \array_slice($lines, $offset));
    }
}
