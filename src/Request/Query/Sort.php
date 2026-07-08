<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Request\Query;

/**
 * One field of a JSON:API `?sort=` parameter; a `-` prefix means descending.
 */
final readonly class Sort
{
    private function __construct(
        public string $field,
        public bool $descending = false,
    ) {
    }

    public static function fromString(string $value): self
    {
        return str_starts_with($value, '-')
            ? new self(substr($value, 1), true)
            : new self($value);
    }
}
