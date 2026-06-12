<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Contract;

interface PagedResultInterface
{
    public function getCurrentPageNumber(): int;

    public function getLastPageNumber(): int;

    public function getTotalResults(): int;

    public function getResultsReturned(): int;

    public function getPerPage(): int;
}
