<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Response\Serializer;

use League\Fractal\Serializer\JsonApiSerializer;
use Stetodd\JsonApiBundle\Response\TransformerRegistry;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class UrlGeneratorAwareJsonApiSerializer extends JsonApiSerializer
{
    /**
     * @param string $relationshipSelfRoutePattern Route name pattern with {type} and {relationship} placeholders
     * @param string $relationshipRelatedRoutePattern Route name pattern with {type} and {relationship} placeholders
     * @param array<string, string> $fieldsets requested sparse fieldsets (type => comma-separated fields)
     */
    public function __construct(
        string $baseUrl,
        private UrlGeneratorInterface $urlGenerator,
        private string $relationshipSelfRoutePattern = 'api_{type}_relationship_{relationship}_self',
        private string $relationshipRelatedRoutePattern = 'api_{type}_relationship_{relationship}_related',
        private ?TransformerRegistry $registry = null,
        private array $fieldsets = [],
    ) {
        parent::__construct($baseUrl);
    }

    /**
     * Builds each resource's links.self from its transformer's #[JsonApiResource]
     * path (e.g. /geospatial/locations/{id}) instead of Fractal's naive
     * {baseUrl}/{type}/{id}. A self link hand-built by the transformer still wins.
     */
    public function item(?string $resourceKey, array $data): array
    {
        $resource = parent::item($resourceKey, $data);

        /** @var array{data: array{id: string, links?: array<string, string>}} $resource */
        $id = $resource['data']['id'];
        $fractalDefault = "{$this->baseUrl}/{$resourceKey}/{$id}";

        if (($resource['data']['links']['self'] ?? null) === $fractalDefault) {
            $path = $this->registry?->resourcePathForType((string) $resourceKey);
            if ($path !== null) {
                $resource['data']['links']['self'] = $this->baseUrl.rtrim($path, '/').'/'.$id;
            }
        }

        return $resource;
    }

    public function injectAvailableIncludeData(array $data, array $availableIncludes): array
    {
        if (!$this->shouldIncludeLinks()) {
            return $data;
        }

        if ($this->isCollection($data)) {
            /** @var array<array-key, mixed> $dataObjects */
            $dataObjects = $data['data'];
            $data['data'] = array_map(function (array $resource) use ($availableIncludes): array {
                /** @var array<array-key, string> $availableIncludes */
                foreach ($availableIncludes as $relationshipKey) {
                    $resource = $this->addRelationshipLinks($resource, $relationshipKey);
                }

                return $resource;
            }, $dataObjects);
        } else {
            /** @var array<array-key, string> $availableIncludes */
            foreach ($availableIncludes as $relationshipKey) {
                /** @var array<array-key, mixed> $dataObjects */
                $dataObjects = $data['data'];
                $data['data'] = $this->addRelationshipLinks($dataObjects, $relationshipKey);
            }
        }

        return $data;
    }

    /**
     * Per the spec, sparse fieldsets restrict relationships too: when a fieldset is
     * requested for the resource's type, only listed relationships get links.
     */
    private function relationshipAllowedByFieldset(string $type, string $relationshipKey): bool
    {
        if (!isset($this->fieldsets[$type])) {
            return true;
        }

        $fields = array_map(trim(...), explode(',', $this->fieldsets[$type]));

        return in_array($relationshipKey, $fields, true);
    }

    /**
     * Adds links for all available includes to a single resource.
     *
     * @param array $resource The resource to add relationship links to
     * @param string $relationshipKey The resource key of the relationship
     */
    private function addRelationshipLinks(array $resource, string $relationshipKey): array
    {
        if (!$this->relationshipAllowedByFieldset((string) $resource['type'], $relationshipKey)) {
            return $resource;
        }

        if (!isset($resource['relationships']) || !isset($resource['relationships'][$relationshipKey])) {
            /**
             * @psalm-suppress MixedArrayAccess
             * @psalm-suppress MixedArrayAssignment
             */
            $resource['relationships'][$relationshipKey] = [];
        }

        /**
         * @psalm-suppress MixedArrayAccess
         *
         * @var array<array-key, mixed> $relationship
         */
        $relationship = $resource['relationships'][$relationshipKey];

        $id = (string) $resource['id'];
        $type = (string) $resource['type'];
        try {
            $self = $this->urlGenerator->generate(
                strtr($this->relationshipSelfRoutePattern, ['{type}' => $type, '{relationship}' => $relationshipKey]),
                [
                    'id' => $id,
                ],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        } catch (RouteNotFoundException) {
            $self = "{$this->baseUrl}/{$type}/{$id}/relationships/{$relationshipKey}";
        }

        try {
            $related = $this->urlGenerator->generate(
                strtr($this->relationshipRelatedRoutePattern, ['{type}' => $type, '{relationship}' => $relationshipKey]),
                [
                    'id' => $id,
                ],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        } catch (RouteNotFoundException) {
            $related = "{$this->baseUrl}/{$type}/{$id}/{$relationshipKey}";
        }

        /** @psalm-suppress MixedArrayAssignment */
        $resource['relationships'][$relationshipKey] = array_merge(
            [
                'links' => [
                    'self' => $self,
                    'related' => $related,
                ],
            ],
            $relationship
        );

        return $resource;
    }
}
