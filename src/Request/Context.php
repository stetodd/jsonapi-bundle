<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Request;

use Symfony\Component\Validator\Constraints as Assert;

class Context
{
    #[Assert\Valid()]
    private ?AttributesDTOInterface $attributes = null;

    #[Assert\Valid()]
    private ?RelationshipsDTOInterface $relationships = null;

    public function getAttributes(): ?AttributesDTOInterface
    {
        if ($this->attributes === null) {
            throw new \RuntimeException('AttributesDTO has not been set');
        }

        return $this->attributes;
    }

    public function setAttributes(AttributesDTOInterface $attributes): void
    {
        $this->attributes = $attributes;
    }

    public function getRelationships(): RelationshipsDTOInterface
    {
        if ($this->relationships === null) {
            throw new \RuntimeException('RelationshipsDTO has not been set');
        }

        return $this->relationships;
    }

    public function setRelationships(RelationshipsDTOInterface $relationships): void
    {
        $this->relationships = $relationships;
    }
}
