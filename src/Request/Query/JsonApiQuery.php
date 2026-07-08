<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Request\Query;

use Symfony\Component\HttpFoundation\Request;

/**
 * The JSON:API query families of a request, parsed once and typed:
 * `?include=`, `?fields[type]=`, `?sort=`, `?page[number]=`, `?page[size]=`.
 * (`?filter[...]=` is deliberately absent — filters are endpoint-specific and map
 * to dedicated request DTOs.)
 */
final readonly class JsonApiQuery
{
    /**
     * @param list<string> $includes include paths, e.g. ["location", "fileUpload.variants"]
     * @param array<string, string> $fields resource type => comma-separated field list
     * @param list<Sort> $sorts
     */
    public function __construct(
        public array $includes = [],
        public array $fields = [],
        public array $sorts = [],
        public ?int $pageNumber = null,
        public ?int $pageSize = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        // Read via the raw array: InputBag::all('key') throws when the parameter is
        // scalar, and both `page` and `fields` may legitimately arrive malformed.
        $query = $request->query->all();
        $page = is_array($query['page'] ?? null) ? $query['page'] : [];
        $fields = is_array($query['fields'] ?? null) ? $query['fields'] : [];

        return new self(
            self::csv($request->query->get('include')),
            array_filter($fields, static fn (mixed $v, mixed $k): bool => is_string($v) && is_string($k), ARRAY_FILTER_USE_BOTH),
            array_map(Sort::fromString(...), self::csv($request->query->get('sort'))),
            self::positiveInt($page['number'] ?? null),
            self::positiveInt($page['size'] ?? null),
        );
    }

    public function hasIncludes(): bool
    {
        return $this->includes !== [];
    }

    public function hasFields(): bool
    {
        return $this->fields !== [];
    }

    public function hasSorts(): bool
    {
        return $this->sorts !== [];
    }

    /**
     * @return list<string>
     */
    private static function csv(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
