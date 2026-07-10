# stetodd/jsonapi-bundle

JSON:API request mapping and response serialisation for Symfony, built on league/fractal.

## Install

```bash
composer require stetodd/jsonapi-bundle
```

Register in `config/bundles.php`:

```php
Stetodd\JsonApiBundle\StetoddJsonApiBundle::class => ['all' => true],
```

Configure in `config/packages/stetodd_json_api.yaml`:

```yaml
stetodd_json_api:
    base_url: 'https://api.example.com'
    # recursion_limit: 4
    # relationship_routes:
    #     self: 'api_{type}_relationship_{relationship}_self'
    #     related: 'api_{type}_relationship_{relationship}_related'
```

## Responses

Inject `Stetodd\JsonApiBundle\Response\JsonApiResponder` into controllers:

```php
return $this->responder->item($palette);
return $this->responder->collection(Palette::class, $palettes, $pagedResult);
```

Resources implement `Contract\IdentifiableResourceInterface` (`getId(): \Stringable|string`). Each resource gets a transformer extending `League\Fractal\TransformerAbstract` and implementing `Contract\ResourceTransformerInterface`; transformers are auto-registered via autoconfiguration — no tagging needed.

Paginated collections take any `Contract\PagedResultInterface`. A fresh Fractal manager is built per response, so no include state leaks between requests (worker-mode safe).

## Query features

The JSON:API query families are parsed by `Request\Query\JsonApiQuery::fromRequest()` and honoured per request:

- **`?include=`** — include paths are validated against the root transformer's available + default includes; an unsupported path is a `400` (per spec). Valid paths are passed to Fractal's `parseIncludes`.
- **`?fields[type]=`** — sparse fieldsets, applied to the primary resource *and* included resources via Fractal's `parseFieldsets`. Per the spec, fieldsets restrict relationships too: relationship links are only emitted for relationships named in the type's fieldset.
- **`?sort=`** — declare a resource's sortable fields on its transformer:

  ```php
  #[JsonApiSortable('createdAt', 'historicalDate')]
  class ArtifactTransformer extends TransformerAbstract implements ResourceTransformerInterface
  ```

  then inject `Request\Query\SortResolver` into the list action and translate the validated sorts into your ordering:

  ```php
  foreach ($sortResolver->forType(Artifact::class) as $sort) {
      // $sort->field, $sort->descending ('-' prefix)
  }
  ```

  An undeclared sort field is a `400` (per spec). The mapping of field names to a concrete ordering stays in the application.
- **`?page[number]=` / `?page[size]=`** — parsed onto `JsonApiQuery->pageNumber/pageSize` for the application's pagination resolver; the responder's pagination links emit the same shape (preserving `page[size]`).
- **`?filter[...]=`** — deliberately not interpreted by the bundle: filter strategy is application-defined. Convention: a list-query DTO with a `filter` property mapped via Symfony's `#[MapQueryString]`, so `?filter[x]=` nests naturally.

## Auto-registered relationship endpoints

Declare a resource's URL prefix and relationships on its transformer:

```php
#[JsonApiResource(path: '/artifacts')]
#[JsonApiRelationship('fileUpload')]
#[JsonApiRelationship('detectedObjects', toMany: true)]
class ArtifactTransformer extends TransformerAbstract implements ResourceTransformerInterface
```

then import the route loader (before your attribute controllers, so a hand-written
route with the same name — e.g. a POST relationship update — wins):

```yaml
api_relationship_endpoints:
    resource: .
    type: stetodd_jsonapi_relationships
```

Each declaration registers `GET {path}/{id}/relationships/{segment}` (resource
linkage) and `GET {path}/{id}/{segment}` (full related resource), named to the
configured `relationship_routes` patterns — the same ones the serializer builds
relationship links from, so links and endpoints stay in lockstep. The relationship
data is fetched through the transformer's own Fractal `include{Name}()` method; the
URL segment is the kebab-cased name (`fileUpload` → `file-upload`, overridable via
`pathSegment:`).

The application provides one service: an implementation of
`Contract\RelationshipSourceResolverInterface`, which loads the parent resource by
id and enforces access control (return `null` → 404; throw an
`AccessDeniedException` → 403). Alias the interface to your implementation:

```yaml
Stetodd\JsonApiBundle\Contract\RelationshipSourceResolverInterface:
    alias: App\Ports\Api\Security\RelationshipSourceResolver
```

## Error objects

HttpExceptions raised on JSON:API routes are rendered as spec error objects
(`application/vnd.api+json`):

```json
{"errors": [{"status": "422", "title": "Unprocessable Entity",
             "detail": "This value should not be blank.",
             "source": {"pointer": "/data/attributes/title"}}]}
```

Validation failures (a `ValidationFailedException` as the exception's `previous`,
which the mapping attributes and `#[MapQueryString]`/`#[MapRequestPayload]` all
produce) yield one error per violation, with `source.parameter` for query input on
safe methods and `source.pointer` for body input. Non-HTTP throwables (genuine
500s) keep Symfony's default handling, so debug traces survive in dev.

## Content negotiation

Per the spec's server responsibilities, requests on JSON:API routes are rejected
when they misuse the JSON:API media type itself: a `Content-Type:
application/vnd.api+json` modified with media type parameters is a `415`, and an
`Accept` header where **every** instance of the JSON:API media type carries
parameters is a `406` (`q` is ignored). Other media types — e.g. plain
`application/json` — pass through untouched. Both rejections render as JSON:API
error objects.

Which routes count as JSON:API (for error rendering and content negotiation) is
decided by route-name prefix:

```yaml
stetodd_json_api:
    # route_name_prefixes: ['api_']
    errors:
        # enabled: true
    content_negotiation:
        # enabled: true
```

## Conformance assertions

`Test\JsonApiAssertionsTrait` (requires phpunit in your dev dependencies) provides
spec-shape assertions for use in any test — one conformance test per surface
catches structural regressions cheaply:

```php
use Stetodd\JsonApiBundle\Test\JsonApiAssertionsTrait;

self::assertJsonApiDocument($decoded);          // top-level document rules
self::assertJsonApiResourceObject($decoded['data'], 'artifact');
self::assertJsonApiResourceLinkage($decoded['data']);  // relationship endpoints
self::assertJsonApiErrorDocument($decoded);     // errors[] shape incl. source
self::assertJsonApiPaginationLinks($decoded);   // self/first/last(/next/prev)
```

## Resource self links

`#[JsonApiResource(path: …)]` also drives each resource's `links.self`: the
serializer emits `{base_url}{path}/{id}` (e.g. `/geospatial/locations/{id}`)
instead of Fractal's naive `{base_url}/{type}/{id}`, so transformers don't build
self links by hand. A `links` key returned from `transform()` still wins — use it
for resources with non-standard URLs (e.g. `/profile/me`).

## Requests

Map JSON:API payloads onto DTOs with controller argument attributes:

```php
public function create(
    #[MapAttributePayload(resourceType: Palette::class)]
    CreatePaletteAttributesRequest $attributes,
): JsonResponse {
```

Attribute DTOs implement `Request\AttributesDTOInterface` (use `AttributesDTOTrait`), relationship DTOs implement `Request\RelationshipsDTOInterface`. When a controller maps both, they are bound together through `Request\Context` / `ContextBindingInterface` (use `ContextBindingTrait`). Payloads are validated with symfony/validator; failures return 422 with violation messages.
