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

Configure in `config/packages/stetodd_jsonapi.yaml`:

```yaml
stetodd_jsonapi:
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

Paginated collections take any `Contract\PagedResultInterface`. The `?include=` query parameter is honoured per request; a fresh Fractal manager is built per response, so no include state leaks between requests (worker-mode safe).

## Requests

Map JSON:API payloads onto DTOs with controller argument attributes:

```php
public function create(
    #[MapAttributePayload(resourceType: Palette::class)]
    CreatePaletteAttributesRequest $attributes,
): JsonResponse {
```

Attribute DTOs implement `Request\AttributesDTOInterface` (use `AttributesDTOTrait`), relationship DTOs implement `Request\RelationshipsDTOInterface`. When a controller maps both, they are bound together through `Request\Context` / `ContextBindingInterface` (use `ContextBindingTrait`). Payloads are validated with symfony/validator; failures return 422 with violation messages.
