<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Request;

trait AttributesDTOTrait
{
    /** @var class-string */
    protected string $resourceType;

    /**
     * @return class-string
     */
    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    public function supports(string $resourceType): bool
    {
        return $resourceType === $this->getResourceType();
    }
}
