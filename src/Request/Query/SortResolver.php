<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Request\Query;

use Stetodd\JsonApiBundle\Response\TransformerRegistry;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Validates a request's `?sort=` against a resource's declared sortable fields
 * (its #[JsonApiSortable] attribute) and hands back the accepted Sort list for the
 * application to translate into an ordering. An unsupported sort field is a 400 per
 * the JSON:API spec.
 */
final class SortResolver
{
    public function __construct(
        private readonly TransformerRegistry $registry,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param class-string $resourceType the transformer key (usually the entity class)
     *
     * @return list<Sort>
     */
    public function forType(string $resourceType): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return [];
        }

        $sorts = JsonApiQuery::fromRequest($request)->sorts;
        if ($sorts === []) {
            return [];
        }

        $allowed = $this->registry->sortableFields($resourceType);

        foreach ($sorts as $sort) {
            if (!in_array($sort->field, $allowed, true)) {
                throw new BadRequestHttpException(sprintf(
                    'Unsupported sort field "%s". Sortable fields: %s.',
                    $sort->field,
                    $allowed === [] ? '(none)' : implode(', ', $allowed),
                ));
            }
        }

        return $sorts;
    }
}
