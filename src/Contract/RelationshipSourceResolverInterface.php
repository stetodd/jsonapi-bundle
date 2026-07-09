<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Contract;

/**
 * Loads the parent resource of an auto-registered relationship endpoint. The
 * application implements this: it owns entity lookup (repositories) and access
 * control (throw an AccessDeniedException for a resource the caller may not view;
 * return null for an unknown id — the endpoint responds 404).
 */
interface RelationshipSourceResolverInterface
{
    /**
     * @param class-string $resourceClass the transformer's resource key
     */
    public function resolve(string $resourceClass, string $id): ?IdentifiableResourceInterface;
}
