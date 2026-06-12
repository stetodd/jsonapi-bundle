<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Request;

use Symfony\Component\Validator\Constraints as Assert;

trait ContextBindingTrait
{
    #[Assert\Valid()]
    protected ?Context $context = null;

    public function getContext(): Context
    {
        if ($this->context === null) {
            throw new \RuntimeException('Context binding has not been set.');
        }

        return $this->context;
    }

    public function setContext(Context $context): void
    {
        $this->context = $context;
    }
}
