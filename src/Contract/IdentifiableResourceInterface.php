<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Contract;

interface IdentifiableResourceInterface
{
    public function getId(): \Stringable|string;
}
