<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Response;

use League\Fractal\TransformerAbstract;
use Stetodd\JsonApiBundle\Contract\ResourceTransformerInterface;
use Stetodd\JsonApiBundle\Request\Query\JsonApiSortable;
use Stetodd\JsonApiBundle\Resource\JsonApiRelationship;
use Stetodd\JsonApiBundle\Resource\JsonApiResource;
use Stetodd\JsonApiBundle\Resource\ResourceKeyGenerator;

class TransformerRegistry
{
    /**
     * @var array<string, TransformerAbstract&ResourceTransformerInterface>
     */
    private array $transformers = [];

    /**
     * @param iterable<array-key, TransformerAbstract&ResourceTransformerInterface> $transformers
     */
    public function __construct(
        iterable $transformers,
    ) {
        foreach ($transformers as $transformer) {
            $this->transformers[$transformer->getKey()] = $transformer;
        }
    }

    public function getTransformer(string $key): TransformerAbstract&ResourceTransformerInterface
    {
        if (!array_key_exists($key, $this->transformers)) {
            throw new \RuntimeException(sprintf('Transformer %s not found', $key));
        }

        return $this->transformers[$key];
    }

    /**
     * The sort fields the resource accepts, from its #[JsonApiSortable] attribute
     * (empty when the resource declares none).
     *
     * @return list<string>
     */
    public function sortableFields(string $key): array
    {
        $reflection = new \ReflectionClass($this->getTransformer($key));

        $fields = [];
        foreach ($reflection->getAttributes(JsonApiSortable::class) as $attribute) {
            foreach ($attribute->newInstance()->fields as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * The relationships the resource declares via #[JsonApiRelationship] attributes.
     *
     * @return list<JsonApiRelationship>
     */
    public function relationships(string $key): array
    {
        $reflection = new \ReflectionClass($this->getTransformer($key));

        return array_map(
            static fn (\ReflectionAttribute $attribute): JsonApiRelationship => $attribute->newInstance(),
            $reflection->getAttributes(JsonApiRelationship::class),
        );
    }

    public function relationship(string $key, string $name): ?JsonApiRelationship
    {
        foreach ($this->relationships($key) as $relationship) {
            if ($relationship->name === $name) {
                return $relationship;
            }
        }

        return null;
    }

    /**
     * All registered resource keys, for iterating declared relationships (route loading).
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->transformers);
    }

    /**
     * The resource's URL path prefix from its #[JsonApiResource] attribute, or null
     * when the resource doesn't declare one (no endpoints are auto-registered).
     */
    public function resourcePath(string $key): ?string
    {
        $reflection = new \ReflectionClass($this->getTransformer($key));

        foreach ($reflection->getAttributes(JsonApiResource::class) as $attribute) {
            return $attribute->newInstance()->path;
        }

        return null;
    }

    /**
     * As {@see resourcePath()}, but looked up by the serialized type string
     * (e.g. "artifact") rather than the registry key — the serializer only knows
     * the type when building resource links.
     */
    public function resourcePathForType(string $type): ?string
    {
        foreach ($this->keys() as $key) {
            if (ResourceKeyGenerator::generateResourceKey($key) === $type) {
                return $this->resourcePath($key);
            }
        }

        return null;
    }
}
