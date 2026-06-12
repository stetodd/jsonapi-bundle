<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Contract;

interface ResourceTransformerInterface
{
    public function getKey(): string;
}
