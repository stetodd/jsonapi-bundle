<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * JSON:API content negotiation (https://jsonapi.org/format/#content-negotiation-servers)
 * for requests on JSON:API routes:
 *
 *  - 415 when the request's Content-Type is the JSON:API media type modified with
 *    media type parameters;
 *  - 406 when the Accept header contains the JSON:API media type and every
 *    instance of it is modified with media type parameters (`q` is ignored).
 *
 * Other media types (e.g. plain application/json) pass through untouched.
 */
final class JsonApiContentNegotiationSubscriber implements EventSubscriberInterface
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param list<string> $routeNamePrefixes
     */
    public function __construct(
        private readonly array $routeNamePrefixes,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After routing (RouterListener, 32) and the firewall (8): auth still wins.
        return [KernelEvents::REQUEST => ['onKernelRequest', 6]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->matches((string) $request->attributes->get('_route'))) {
            return;
        }

        $contentType = (string) $request->headers->get('Content-Type', '');
        if ($this->isParameterizedJsonApi($contentType)) {
            throw new UnsupportedMediaTypeHttpException(sprintf(
                'The %s media type must not be modified with media type parameters.',
                self::MEDIA_TYPE,
            ));
        }

        $accept = (string) $request->headers->get('Accept', '');
        if ($accept !== '' && $this->allJsonApiInstancesParameterized($accept)) {
            throw new NotAcceptableHttpException(sprintf(
                'The Accept header contains the %s media type only with media type parameters.',
                self::MEDIA_TYPE,
            ));
        }
    }

    private function matches(string $route): bool
    {
        foreach ($this->routeNamePrefixes as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isParameterizedJsonApi(string $contentType): bool
    {
        [$type, $parameters] = $this->splitMediaType($contentType);

        return $type === self::MEDIA_TYPE && $parameters !== [];
    }

    private function allJsonApiInstancesParameterized(string $accept): bool
    {
        $sawJsonApi = false;

        foreach (explode(',', $accept) as $instance) {
            [$type, $parameters] = $this->splitMediaType($instance);
            if ($type !== self::MEDIA_TYPE) {
                continue;
            }

            $sawJsonApi = true;
            if ($parameters === []) {
                return false;
            }
        }

        return $sawJsonApi;
    }

    /**
     * @return array{string, list<string>} lowercased type + parameters (`q` excluded)
     */
    private function splitMediaType(string $mediaType): array
    {
        $parts = array_map(trim(...), explode(';', $mediaType));
        $type = strtolower((string) array_shift($parts));

        $parameters = array_values(array_filter(
            $parts,
            static fn (string $parameter): bool => $parameter !== '' && !str_starts_with(strtolower($parameter), 'q='),
        ));

        return [$type, $parameters];
    }
}
