<?php

declare(strict_types=1);

namespace Castor\Docker\Doctor;

/**
 * What one check of "docker:doctor" found, and how to fix it when something is
 * wrong.
 */
final readonly class Check
{
    public function __construct(
        public Status $status,
        public string $label,
        public string $result,
        public ?string $fix = null,
    ) {}

    public static function ok(string $label, string $result): self
    {
        return new self(Status::Ok, $label, $result);
    }

    public static function warning(string $label, string $result, string $fix): self
    {
        return new self(Status::Warning, $label, $result, $fix);
    }

    public static function error(string $label, string $result, string $fix): self
    {
        return new self(Status::Error, $label, $result, $fix);
    }

    public static function skipped(string $label, string $result): self
    {
        return new self(Status::Skipped, $label, $result);
    }
}
