<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Resource;

use function Symfony\Component\String\u;

class ResourceKeyGenerator
{
    /**
     * @param object|class-string $object
     */
    public static function generateResourceKey(object|string $object): string
    {
        return u(lcfirst(
            (new \ReflectionClass($object))->getShortName()
        ))
            ->kebab()
            ->lower()
            ->toString();
    }
}
