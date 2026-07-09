<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Resource;

/**
 * Declares, on a resource transformer, the URL path prefix under which the
 * resource's items live (e.g. `/artifacts`). Required for its #[JsonApiRelationship]
 * declarations to be auto-registered as relationship endpoints
 * (`{path}/{id}/relationships/{segment}` and `{path}/{id}/{segment}`).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class JsonApiResource
{
    public function __construct(
        public string $path,
    ) {
    }
}
