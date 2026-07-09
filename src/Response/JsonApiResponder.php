<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Response;

use Doctrine\Persistence\Proxy;
use League\Fractal\Manager;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use League\Fractal\Resource\NullResource;
use League\Fractal\Resource\ResourceAbstract;
use League\Fractal\TransformerAbstract;
use Stetodd\JsonApiBundle\Contract\IdentifiableResourceInterface;
use Stetodd\JsonApiBundle\Contract\PagedResultInterface;
use Stetodd\JsonApiBundle\Request\Query\JsonApiQuery;
use Stetodd\JsonApiBundle\Resource\ResourceKeyGenerator;
use Stetodd\JsonApiBundle\Response\Pagination\PagedResultPaginationAdapter;
use Stetodd\JsonApiBundle\Response\Serializer\UrlGeneratorAwareJsonApiSerializer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

final class JsonApiResponder
{
    public const string CONTENT_TYPE = 'application/vnd.api+json';

    public function __construct(
        private TransformerRegistry $transformerRegistry,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private string $baseUrl,
        private string $relationshipSelfRoutePattern = 'api_{type}_relationship_{relationship}_self',
        private string $relationshipRelatedRoutePattern = 'api_{type}_relationship_{relationship}_related',
        private int $recursionLimit = 4,
        private ?SerializerInterface $serializer = null,
    ) {
    }

    public function item(?IdentifiableResourceInterface $data, array $meta = [], int $status = 200, array $headers = [], array $context = []): JsonResponse
    {
        if ($data === null) {
            $data = $this->createManager()->createData(new NullResource())->toArray();

            return $this->json($data, $status, $headers, $context);
        }

        $transformer = $this->getTransformer($data::class);
        $manager = $this->createManager($transformer);

        $resource = new Item(
            $data,
            $transformer,
            ResourceKeyGenerator::generateResourceKey($data),
        );
        $resource->setMeta($meta);

        $data = $manager->createData($resource)->toArray();

        return $this->json($data, $status, $headers, $context);
    }

    public function itemInclude(?IdentifiableResourceInterface $data, int $status = 200, array $headers = [], array $context = []): JsonResponse
    {
        if ($data === null) {
            $manager = $this->createManager();
            $data = $manager->createData(new NullResource())->toArray();

            return $this->json($data, $status, $headers, $context);
        }

        $data = ['data' => [
            'id' => (string) $data->getId(),
            'type' => ResourceKeyGenerator::generateResourceKey($data),
        ]];

        return $this->json($data, $status, $headers, $context);
    }

    /**
     * @param class-string<IdentifiableResourceInterface> $dataType
     */
    public function collection(
        string $dataType,
        iterable $collection,
        ?PagedResultInterface $pagedResult = null,
        int $status = 200,
        array $headers = [],
        array $context = [],
    ): JsonResponse {
        $transformer = $this->getTransformer($dataType);
        $manager = $this->createManager($transformer);

        $resource = new Collection(
            $collection,
            $transformer,
            ResourceKeyGenerator::generateResourceKey($dataType),
        );

        if ($pagedResult !== null) {
            $resource->setPaginator(
                PagedResultPaginationAdapter::createWithRouteGenerator(
                    $pagedResult,
                    $this->createPageUrlGenerator()
                )
            );
        }

        $data = $manager->createData($resource)->toArray();

        return $this->json($data, $status, $headers, $context);
    }

    /**
     * Serialize a prepared Fractal resource (Item, Collection or NullResource) —
     * e.g. one produced by a transformer's include method. Query features (includes,
     * fieldsets) are honoured against the resource's own transformer.
     */
    public function fractalResource(ResourceAbstract $resource, int $status = 200, array $headers = [], array $context = []): JsonResponse
    {
        $transformer = $resource->getTransformer();
        $manager = $this->createManager($transformer instanceof TransformerAbstract ? $transformer : null);

        return $this->json($manager->createData($resource)->toArray(), $status, $headers, $context);
    }

    public function collectionInclude(iterable $collection, int $status = 200, array $headers = [], array $context = []): JsonResponse
    {
        return $this->json(['data' => array_map(
            fn (IdentifiableResourceInterface $data): array => [
                'id' => (string) $data->getId(),
                'type' => ResourceKeyGenerator::generateResourceKey($data),
            ],
            $collection instanceof \Traversable ? iterator_to_array($collection) : $collection
        )],
            $status,
            $headers,
            $context
        );
    }

    public function json(mixed $data, int $status = 200, array $headers = [], array $context = []): JsonResponse
    {
        $headers['Content-Type'] = self::CONTENT_TYPE;

        if ($this->serializer === null) {
            return new JsonResponse($data, $status, $headers);
        }

        $context[AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER] = function (IdentifiableResourceInterface $object, string $_format, array $_context): string {
            return (string) $object->getId();
        };

        $json = $this->serializer->serialize(
            $data,
            'json',
            array_merge(['json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS], $context)
        );

        return new JsonResponse($json, $status, $headers, true);
    }

    private function getTransformer(string $classString): TransformerAbstract
    {
        return $this->transformerRegistry->getTransformer($this->resolveClass($classString));
    }

    /**
     * Doctrine lazily loads related entities through generated proxy classes;
     * transformers are registered against the real entity class.
     */
    private function resolveClass(string $classString): string
    {
        if (interface_exists(Proxy::class) && is_subclass_of($classString, Proxy::class)) {
            $parent = get_parent_class($classString);
            if ($parent !== false) {
                return $parent;
            }
        }

        return $classString;
    }

    private function createManager(?TransformerAbstract $transformer = null): Manager
    {
        $request = $this->requestStack->getCurrentRequest();
        $query = $request !== null ? JsonApiQuery::fromRequest($request) : new JsonApiQuery();

        $manager = new Manager();
        $manager->setRecursionLimit($this->recursionLimit);
        $manager->setSerializer(
            new UrlGeneratorAwareJsonApiSerializer(
                $this->baseUrl,
                $this->urlGenerator,
                $this->relationshipSelfRoutePattern,
                $this->relationshipRelatedRoutePattern,
                $this->transformerRegistry,
                $query->fields,
            )
        );

        if ($query->hasIncludes()) {
            if ($transformer !== null) {
                $this->assertIncludesAreSupported($query->includes, $transformer);
            }
            $manager->parseIncludes($query->includes);
        }

        if ($query->hasFields()) {
            $manager->parseFieldsets($query->fields);
        }

        return $manager;
    }

    /**
     * Per the JSON:API spec a request with an unsupported include path MUST be
     * rejected with a 400. Validated against the root transformer's available and
     * default includes (top-level path segment).
     *
     * @param list<string> $includes
     */
    private function assertIncludesAreSupported(array $includes, TransformerAbstract $transformer): void
    {
        $supported = array_merge($transformer->getAvailableIncludes(), $transformer->getDefaultIncludes());

        foreach ($includes as $include) {
            $root = explode('.', $include, 2)[0];
            if (!in_array($root, $supported, true)) {
                throw new BadRequestHttpException(sprintf(
                    'Unsupported include path "%s". Supported includes: %s.',
                    $include,
                    implode(', ', $supported),
                ));
            }
        }
    }

    /**
     * @return \Closure(int): string
     */
    private function createPageUrlGenerator(): \Closure
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return fn (int $page): string => sprintf('?page%%5Bnumber%%5D=%d', $page);
        }

        return function (int $page) use ($request): string {
            $route = (string) $request->attributes->get('_route');
            $inputParams = (array) $request->attributes->get('_route_params');
            $newParams = array_merge($inputParams, $request->query->all());

            // JSON:API pagination family: swap the page number, keep e.g. page[size].
            $currentPage = $newParams['page'] ?? [];
            $newParams['page'] = array_merge(is_array($currentPage) ? $currentPage : [], ['number' => $page]);

            return $this->urlGenerator->generate($route, $newParams, UrlGeneratorInterface::ABSOLUTE_URL);
        };
    }
}
