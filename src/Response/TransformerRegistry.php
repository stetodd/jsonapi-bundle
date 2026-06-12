<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Response;

use League\Fractal\TransformerAbstract;
use Stetodd\JsonApiBundle\Contract\ResourceTransformerInterface;

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
}
