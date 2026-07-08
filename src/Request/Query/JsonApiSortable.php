<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Request\Query;

/**
 * Declares, on a resource transformer, which JSON:API sort fields the resource
 * accepts in `?sort=`. Any other field is rejected with a 400. The mapping of these
 * field names to a concrete ordering is the application's concern.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class JsonApiSortable
{
    /**
     * @var list<string>
     */
    public array $fields;

    public function __construct(string ...$fields)
    {
        $this->fields = array_values($fields);
    }
}
