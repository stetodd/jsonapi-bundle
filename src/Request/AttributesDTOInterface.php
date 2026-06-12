<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Request;

interface AttributesDTOInterface
{
    public function supports(string $resourceType): bool;

    /**
     * @return class-string
     */
    public function getResourceType(): string;
}
