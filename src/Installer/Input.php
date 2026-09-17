<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

/**
 * One question an installer needs answered. The same schema drives the
 * interactive prompt and the option answering it on the command line, see
 * {@see InstallerOptions}.
 */
final class Input
{
    /**
     * @param list<string>                                  $choices  for InputType::Choice
     * @param mixed|(\Closure(array<string, mixed>): mixed) $default  a value, or a closure computing it from the answers gathered so far (e.g. directory from name); a multiple choice preselects a list<string>
     * @param bool                                          $multiple for InputType::Choice, whether several choices can be picked at once — the answer is then a list<string> rather than a string
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly InputType $type = InputType::Text,
        public readonly mixed $default = null,
        public readonly array $choices = [],
        public readonly bool $multiple = false,
    ) {}

    /**
     * @param array<string, mixed> $answers
     */
    public function resolveDefault(array $answers): mixed
    {
        return $this->default instanceof \Closure ? ($this->default)($answers) : $this->default;
    }
}
