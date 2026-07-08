<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Response;

use League\Fractal\TransformerAbstract;
use Stetodd\JsonApiBundle\Contract\ResourceTransformerInterface;
use Stetodd\JsonApiBundle\Request\Query\JsonApiSortable;

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
}
