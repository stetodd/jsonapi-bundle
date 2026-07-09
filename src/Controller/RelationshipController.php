<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Controller;

use League\Fractal\Resource\NullResource;
use League\Fractal\Resource\ResourceAbstract;
use Stetodd\JsonApiBundle\Contract\IdentifiableResourceInterface;
use Stetodd\JsonApiBundle\Contract\RelationshipSourceResolverInterface;
use Stetodd\JsonApiBundle\Resource\JsonApiRelationship;
use Stetodd\JsonApiBundle\Response\JsonApiResponder;
use Stetodd\JsonApiBundle\Response\TransformerRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the auto-registered relationship endpoints. The parent resource is loaded
 * (and authorised) by the application's RelationshipSourceResolverInterface; the
 * relationship's data comes from the transformer's own Fractal include method — the
 * single place that already knows how to fetch it.
 */
final class RelationshipController
{
    public function __construct(
        private readonly TransformerRegistry $registry,
        private readonly JsonApiResponder $responder,
        private readonly ?RelationshipSourceResolverInterface $sourceResolver = null,
    ) {
    }

    /**
     * `…/{id}/relationships/{segment}` — resource linkage (type + id only).
     *
     * @param class-string $resourceKey
     */
    public function self(string $id, string $resourceKey, string $relationshipName): JsonResponse
    {
        [$relationship, $resource] = $this->loadRelationship($id, $resourceKey, $relationshipName);

        $data = $resource instanceof NullResource ? null : $resource->getData();

        if ($relationship->toMany) {
            /** @var iterable<IdentifiableResourceInterface> $collection */
            $collection = $data ?? [];

            return $this->responder->collectionInclude($collection);
        }

        \assert($data === null || $data instanceof IdentifiableResourceInterface);

        return $this->responder->itemInclude($data);
    }

    /**
     * `…/{id}/{segment}` — the related resource(s), fully serialized.
     *
     * @param class-string $resourceKey
     */
    public function related(string $id, string $resourceKey, string $relationshipName): JsonResponse
    {
        [, $resource] = $this->loadRelationship($id, $resourceKey, $relationshipName);

        return $this->responder->fractalResource($resource);
    }

    /**
     * @param class-string $resourceKey
     *
     * @return array{JsonApiRelationship, ResourceAbstract}
     */
    private function loadRelationship(string $id, string $resourceKey, string $relationshipName): array
    {
        if ($this->sourceResolver === null) {
            throw new \LogicException(sprintf(
                'Auto-registered relationship endpoints need an implementation of %s to load (and authorise) the parent resource.',
                RelationshipSourceResolverInterface::class,
            ));
        }

        $relationship = $this->registry->relationship($resourceKey, $relationshipName)
            ?? throw new NotFoundHttpException(sprintf('Unknown relationship "%s".', $relationshipName));

        $entity = $this->sourceResolver->resolve($resourceKey, $id)
            ?? throw new NotFoundHttpException('Resource not found.');

        $transformer = $this->registry->getTransformer($resourceKey);
        $includeMethod = $relationship->includeMethod();

        if (!method_exists($transformer, $includeMethod)) {
            throw new \LogicException(sprintf(
                '%s declares relationship "%s" but has no %s() include method.',
                $transformer::class,
                $relationshipName,
                $includeMethod,
            ));
        }

        /** @var ResourceAbstract $resource */
        $resource = $transformer->{$includeMethod}($entity);

        return [$relationship, $resource];
    }
}
