<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

/**
 * One question an installer needs answered. The same schema drives the
 * interactive prompt and (later) the per-installer CLI flags.
 */
final class Input
{
    /**
     * @param list<string>                                    $choices for InputType::Choice
     * @param mixed|(\Closure(array<string, mixed>): mixed)   $default a value, or a closure computing it from the answers gathered so far (e.g. directory from name)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly InputType $type = InputType::Text,
        public readonly mixed $default = null,
        public readonly array $choices = [],
    ) {}

    /**
     * @param array<string, mixed> $answers
     */
    public function resolveDefault(array $answers): mixed
    {
        return $this->default instanceof \Closure ? ($this->default)($answers) : $this->default;
    }
}
