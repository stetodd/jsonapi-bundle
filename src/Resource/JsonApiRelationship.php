<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Resource;

use Symfony\Component\String\UnicodeString;

/**
 * Declares, on a resource transformer, a relationship the resource exposes as
 * JSON:API relationship endpoints (`…/{id}/relationships/{name}` and the related
 * resource URL). The relationship's data is fetched through the transformer's
 * matching Fractal include method (`include{Name}()`), which is the single place
 * that already knows how to load it.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class JsonApiRelationship
{
    public function __construct(
        public string $name,
        public bool $toMany = false,
        private ?string $pathSegment = null,
    ) {
    }

    /**
     * URL path segment for the relationship, e.g. `fileUpload` → `file-upload`.
     */
    public function pathSegment(): string
    {
        return $this->pathSegment ?? (new UnicodeString($this->name))->snake()->replace('_', '-')->toString();
    }

    /**
     * The Fractal include method on the transformer that loads this relationship.
     */
    public function includeMethod(): string
    {
        return 'include'.ucfirst($this->name);
    }
}
