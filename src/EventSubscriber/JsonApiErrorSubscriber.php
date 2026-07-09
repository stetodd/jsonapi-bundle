<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Renders HttpExceptions raised on JSON:API routes as spec error objects
 * (https://jsonapi.org/format/#errors): an `errors[]` array with status/title/detail
 * and a `source` (`parameter` for query input on safe methods, `pointer` for body
 * input) per validation violation. Non-HTTP throwables (genuine 500s) are left to
 * Symfony's default handling so debug traces survive in dev.
 */
final class JsonApiErrorSubscriber implements EventSubscriberInterface
{
    public const string CONTENT_TYPE = 'application/vnd.api+json';

    /**
     * @param list<string> $routeNamePrefixes routes whose errors are rendered as JSON:API
     */
    public function __construct(
        private readonly array $routeNamePrefixes,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Above Symfony's ErrorListener (-128), which would otherwise render problem+json.
        return [KernelEvents::EXCEPTION => ['onKernelException', 8]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if (!$throwable instanceof HttpExceptionInterface) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route');
        if (!$this->matches($route)) {
            return;
        }

        $status = $throwable->getStatusCode();
        $headers = $throwable->getHeaders();
        $headers['Content-Type'] = self::CONTENT_TYPE;

        $event->setResponse(new JsonResponse(
            ['errors' => $this->errors($throwable, $event->getRequest(), $status)],
            $status,
            $headers,
        ));
        $event->stopPropagation();
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

    /**
     * @return list<array<string, mixed>>
     */
    private function errors(HttpExceptionInterface $throwable, Request $request, int $status): array
    {
        $title = Response::$statusTexts[$status] ?? 'Error';

        $previous = $throwable->getPrevious();
        if ($previous instanceof ValidationFailedException) {
            $errors = [];
            foreach ($previous->getViolations() as $violation) {
                $error = [
                    'status' => (string) $status,
                    'title' => $title,
                    'detail' => (string) $violation->getMessage(),
                ];

                $source = $this->source((string) $violation->getPropertyPath(), $request);
                if ($source !== null) {
                    $error['source'] = $source;
                }

                $errors[] = $error;
            }

            if ($errors !== []) {
                return $errors;
            }
        }

        $error = ['status' => (string) $status, 'title' => $title];
        if ($throwable->getMessage() !== '') {
            $error['detail'] = $throwable->getMessage();
        }

        return [$error];
    }

    /**
     * Safe methods take input from the query string (`source.parameter`); writes
     * take it from the document body (`source.pointer`, assumed under attributes).
     *
     * @return array<string, string>|null
     */
    private function source(string $propertyPath, Request $request): ?array
    {
        if ($propertyPath === '') {
            return null;
        }

        $segments = preg_split('/\.|\[|\]/', $propertyPath, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        if ($segments === []) {
            return null;
        }

        if ($request->isMethodSafe()) {
            $parameter = array_shift($segments);
            foreach ($segments as $segment) {
                $parameter .= sprintf('[%s]', $segment);
            }

            return ['parameter' => $parameter];
        }

        return ['pointer' => '/data/attributes/'.implode('/', $segments)];
    }
}
