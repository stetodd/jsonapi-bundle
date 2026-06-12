<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Response\Pagination;

use League\Fractal\Pagination\PaginatorInterface;
use Stetodd\JsonApiBundle\Contract\PagedResultInterface;

class PagedResultPaginationAdapter implements PaginatorInterface
{
    /** @var \Closure(int): string|null */
    private ?\Closure $routeGenerator = null;

    private function __construct(private PagedResultInterface $pagedResult)
    {
    }

    public static function create(PagedResultInterface $pagedResult): self
    {
        return new self($pagedResult);
    }

    /**
     * @param \Closure(int): string $routeGenerator
     */
    public static function createWithRouteGenerator(PagedResultInterface $pagedResult, \Closure $routeGenerator): self
    {
        $self = new self($pagedResult);
        $self->setRouteGenerator($routeGenerator);

        return $self;
    }

    /**
     * @param \Closure(int): string $routeGenerator
     */
    public function setRouteGenerator(\Closure $routeGenerator): void
    {
        $this->routeGenerator = $routeGenerator;
    }

    public function getCurrentPage(): int
    {
        return $this->pagedResult->getCurrentPageNumber();
    }

    public function getLastPage(): int
    {
        return $this->pagedResult->getLastPageNumber();
    }

    public function getTotal(): int
    {
        return $this->pagedResult->getTotalResults();
    }

    public function getCount(): int
    {
        return $this->pagedResult->getResultsReturned();
    }

    public function getPerPage(): int
    {
        return $this->pagedResult->getPerPage();
    }

    public function getUrl(int $page): string
    {
        if ($this->routeGenerator === null) {
            return sprintf('?page=%d', $page);
        }

        return ($this->routeGenerator)($page);
    }
}
