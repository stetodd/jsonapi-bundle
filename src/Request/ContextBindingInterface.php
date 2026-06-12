<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Request;

interface ContextBindingInterface
{
    public function setContext(Context $context): void;

    public function getContext(): Context;
}
