<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Routing;

use Stetodd\JsonApiBundle\Controller\RelationshipController;
use Stetodd\JsonApiBundle\Resource\ResourceKeyGenerator;
use Stetodd\JsonApiBundle\Response\TransformerRegistry;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Registers relationship endpoints for every transformer that declares
 * #[JsonApiResource(path: …)] + #[JsonApiRelationship(…)]:
 *
 *   GET {path}/{id}/relationships/{segment}  → api_{type}_relationship_{name}_self
 *   GET {path}/{id}/{segment}                → api_{type}_relationship_{name}_related
 *
 * The route names follow the same configured patterns the serializer uses for
 * relationship links, so the links point at these endpoints without further wiring.
 * A hand-written route with the same name wins when its definition loads later.
 */
final class RelationshipRouteLoader extends Loader
{
    public const string TYPE = 'stetodd_jsonapi_relationships';

    public function __construct(
        private readonly TransformerRegistry $registry,
        private readonly string $selfRoutePattern,
        private readonly string $relatedRoutePattern,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();

        foreach ($this->registry->keys() as $key) {
            $path = $this->registry->resourcePath($key);
            if ($path === null) {
                continue;
            }

            $path = rtrim($path, '/');
            $typeKey = ResourceKeyGenerator::generateResourceKey($key);

            foreach ($this->registry->relationships($key) as $relationship) {
                $placeholders = ['{type}' => $typeKey, '{relationship}' => $relationship->name];

                $routes->add(
                    strtr($this->selfRoutePattern, $placeholders),
                    $this->route(
                        sprintf('%s/{id}/relationships/%s', $path, $relationship->pathSegment()),
                        RelationshipController::class.'::self',
                        $key,
                        $relationship->name,
                    ),
                );

                $routes->add(
                    strtr($this->relatedRoutePattern, $placeholders),
                    $this->route(
                        sprintf('%s/{id}/%s', $path, $relationship->pathSegment()),
                        RelationshipController::class.'::related',
                        $key,
                        $relationship->name,
                    ),
                );
            }
        }

        return $routes;
    }

    private function route(string $path, string $controller, string $resourceKey, string $relationshipName): Route
    {
        return new Route(
            path: $path,
            defaults: [
                '_controller' => $controller,
                '_format' => 'json',
                'resourceKey' => $resourceKey,
                'relationshipName' => $relationshipName,
            ],
            methods: ['GET'],
        );
    }
}
