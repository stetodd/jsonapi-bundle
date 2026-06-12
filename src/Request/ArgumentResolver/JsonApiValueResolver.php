<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Request\ArgumentResolver;

use Stetodd\JsonApiBundle\Request\Attribute\MapAttributePayload;
use Stetodd\JsonApiBundle\Request\Attribute\MapRelationshipPayload;
use Stetodd\JsonApiBundle\Request\AttributesDTOInterface;
use Stetodd\JsonApiBundle\Request\Context;
use Stetodd\JsonApiBundle\Request\ContextBindingInterface;
use Stetodd\JsonApiBundle\Request\RelationshipsDTOInterface;
use Stetodd\JsonApiBundle\Resource\ResourceKeyGenerator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NearMissValueResolverException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Exception\UnexpectedPropertyException;
use Symfony\Component\Serializer\Exception\UnsupportedFormatException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class JsonApiValueResolver implements ValueResolverInterface, EventSubscriberInterface
{
    private const array CONTEXT_DENORMALIZE = [
        'disable_type_enforcement' => true,
        'collect_denormalization_errors' => true,
    ];

    /**
     * @see DenormalizerInterface::COLLECT_DENORMALIZATION_ERRORS
     */
    private const array CONTEXT_DESERIALIZE = [
        'collect_denormalization_errors' => true,
    ];

    public function __construct(
        private readonly SerializerInterface&DenormalizerInterface $serializer,
        private readonly ?ValidatorInterface $validator = null,
        private readonly ?TranslatorInterface $translator = null,
        private string $translationDomain = 'validators',
    ) {
    }

    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        $attribute = $argument->getAttributesOfType(MapAttributePayload::class, ArgumentMetadata::IS_INSTANCEOF)[0]
            ?? $argument->getAttributesOfType(MapRelationshipPayload::class, ArgumentMetadata::IS_INSTANCEOF)[0]
            ?? null;

        if (!$attribute) {
            return [];
        }

        /**
         * @psalm-suppress RedundantConditionGivenDocblockType
         * If we support more attribute types here then this will become necessary. Currently, it is not with
         * there only being one attribute type supported.
         */
        if ($attribute instanceof MapAttributePayload || $attribute instanceof MapRelationshipPayload) {
            if ($argument->getType() === 'array') {
                if ($attribute->type === '') {
                    throw new NearMissValueResolverException(\sprintf('Please set the $type argument of the #[%s] attribute to the type of the objects in the expected array.', MapAttributePayload::class));
                }
            } elseif ($attribute->type !== null) {
                throw new NearMissValueResolverException(\sprintf('Please set its type to "array" when using argument $type of #[%s].', MapAttributePayload::class));
            }
        }

        $attribute->metadata = $argument;

        return [$attribute];
    }

    public function onKernelControllerArguments(ControllerArgumentsEvent $event): void
    {
        /** @var array<array-key, MapAttributePayload|MapRelationshipPayload|object|string|int> $arguments */
        $arguments = $event->getArguments();

        $violations = [];
        $attributes = [];
        foreach ($arguments as $i => $argument) {
            if ($argument instanceof MapAttributePayload) {
                /** @var MapAttributePayload $argument */
                $payloadMapper = $this->mapAttributePayload(...);
                $validationFailedCode = $argument->validationFailedStatusCode;
            } elseif ($argument instanceof MapRelationshipPayload) {
                /** @var MapRelationshipPayload $argument */
                $payloadMapper = $this->mapRelationshipsPayload(...);
                $validationFailedCode = $argument->validationFailedStatusCode;
            } else {
                continue;
            }
            $request = $event->getRequest();

            $attributes[$i] = $argument;
            if ($argument->metadata->getType() === null || $argument->metadata->getType() === '') {
                throw new \LogicException(\sprintf('Could not resolve the "$%s" controller argument: argument should be typed.', $argument->metadata->getName()));
            }

            if ($this->validator) {
                $violations[$i] = new ConstraintViolationList();

                try {
                    /** @var MapAttributePayload|MapRelationshipPayload $argument */
                    /** @psalm-suppress PossiblyInvalidArgument */
                    $payload = $payloadMapper($request, $argument->metadata, $argument);
                } catch (PartialDenormalizationException $e) {
                    $trans = $this->translator ? $this->translator->trans(...) : fn (string $m, array $p): string => strtr($m, $p);
                    foreach ($e->getErrors() as $error) {
                        $parameters = [];
                        $template = 'This value was of an unexpected type.';
                        if (is_array($expectedTypes = $error->getExpectedTypes())) {
                            $template = 'This value should be of type {{ type }}.';
                            $parameters['{{ type }}'] = implode('|', $expectedTypes);
                        }
                        if ($error->canUseMessageForUser()) {
                            $parameters['hint'] = $error->getMessage();
                        }
                        /** @psalm-suppress TooManyArguments */
                        $message = $trans($template, $parameters, $this->translationDomain);
                        $violations[$i]->add(new ConstraintViolation($message, $template, $parameters, null, $error->getPath(), null));
                    }
                    /** @psalm-suppress MixedAssignment */
                    $payload = $e->getData();
                }

                if (\count($violations[$i])) {
                    throw HttpException::fromStatusCode($validationFailedCode, implode("\n", array_map(static fn ($e) => $e->getMessage(), iterator_to_array($violations[$i]))), new ValidationFailedException($payload, $violations[$i]));
                }
            } else {
                try {
                    /** @var MapAttributePayload|MapRelationshipPayload $argument */
                    /** @psalm-suppress PossiblyInvalidArgument */
                    $payload = $payloadMapper($request, $argument->metadata, $argument);
                } catch (PartialDenormalizationException $e) {
                    throw HttpException::fromStatusCode($validationFailedCode, implode("\n", array_map(static fn ($e) => $e->getMessage(), $e->getErrors())), $e);
                }
            }

            /** @psalm-suppress MixedAssignment */
            $arguments[$i] = $payload;
        }

        $context = new Context();
        /** @var array<array-key, mixed> $arguments */
        /** @psalm-suppress MixedAssignment */
        foreach ($arguments as $i => $argument) {
            if ($argument instanceof AttributesDTOInterface) {
                $context->setAttributes($argument);
            }

            if ($argument instanceof RelationshipsDTOInterface) {
                $context->setRelationships($argument);
            }

            if ($argument instanceof ContextBindingInterface) {
                $argument->setContext($context);
            }
        }

        // Test for validation violations
        foreach ($violations as $i => $violationList) {
            $attribute = $attributes[$i]; // This will be the Attribute

            /** @psalm-suppress MixedAssignment */
            $payload = $arguments[$i];
            if ($this->validator) {
                if ($payload !== null && !\count($violationList)) {
                    $constraints = null;
                    if (property_exists($attribute, 'constraints')) {
                        /**
                         * @var Constraint|array<array-key, Constraint>|null $constraints
                         *
                         * @psalm-suppress UndefinedPropertyFetch
                         */
                        $constraints = $attribute->constraints;
                    }

                    if (\is_array($payload) && (is_array($constraints) && $constraints !== [])) {
                        $constraints = new Assert\All($constraints);
                    }
                    $violationList->addAll($this->validator->validate($payload, $constraints, $attribute->validationGroups ?? null));
                }
            }

            if (\count($violationList)) {
                throw HttpException::fromStatusCode($attribute->validationFailedStatusCode, implode("\n", array_map(static fn ($e) => $e->getMessage(), iterator_to_array($violationList))), new ValidationFailedException($payload, $violationList));
            }
        }

        // Set any default values
        foreach ($attributes as $i => $attribute) {
            /** @psalm-suppress MixedAssignment */
            $payload = $arguments[$i];
            if ($payload === null) {
                /** @psalm-suppress MixedAssignment */
                $arguments[$i] = match (true) {
                    $attribute->metadata->hasDefaultValue() => $attribute->metadata->getDefaultValue(),
                    $attribute->metadata->isNullable() => null,
                    default => throw HttpException::fromStatusCode($attribute->validationFailedStatusCode),
                };
            }
        }

        $event->setArguments($arguments);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER_ARGUMENTS => 'onKernelControllerArguments',
        ];
    }

    private function mapAttributePayload(Request $request, ArgumentMetadata $argument, MapAttributePayload $attribute): object|array|null
    {
        if (null === $format = $request->getContentTypeFormat()) {
            throw new UnsupportedMediaTypeHttpException('Unsupported format.');
        }

        if ($attribute->acceptFormat !== null && !\in_array($format, (array) $attribute->acceptFormat, true)) {
            throw new UnsupportedMediaTypeHttpException(\sprintf('Unsupported format, expects "%s", but "%s" given.', implode('", "', (array) $attribute->acceptFormat), $format));
        }

        if ($argument->getType() === 'array' && $attribute->type !== null) {
            $isArray = true;
            $type = $attribute->type.'[]';
        } else {
            $isArray = false;
            $type = (string) $argument->getType();
        }

        if ('' === ($data = $request->getContent()) && ($argument->isNullable() || $argument->hasDefaultValue())) {
            return null;
        }

        if ($format === 'form') {
            throw new BadRequestHttpException('Request payload contains invalid "form" data.');
        }

        try {
            /** @var ?object $content */
            $content = json_decode($data, false);
            if ($content === null) {
                throw HttpException::fromStatusCode($attribute->validationFailedStatusCode, json_last_error_msg());
            }

            if (!property_exists($content, 'data')) {
                throw HttpException::fromStatusCode($attribute->validationFailedStatusCode, '"data" key is missing.');
            }

            /** @var array|object|null $data */
            $data = $content->data;
            if ($isArray && is_array($data)) {
                /** @var object[] $data */
                $attributes = array_map(function (object $item) use ($attribute): object {
                    return $this->extractAttributes($item, $attribute);
                }, $data);
            } elseif (is_object($data)) {
                $attributes = $this->extractAttributes($data, $attribute);
            } else {
                throw HttpException::fromStatusCode($attribute->validationFailedStatusCode, '"data" needs to be an object or an array.');
            }

            $dataToDeserialise = json_encode($attributes);

            try {
                /** @var AttributesDTOInterface|AttributesDTOInterface[] $payload */
                $payload = $this->serializer->deserialize($dataToDeserialise, $type, $format, self::CONTEXT_DESERIALIZE + $attribute->serializationContext);
            } catch (\InvalidArgumentException $e) {
                // Convert InvalidArgumentException to a validation violation for better error messages
                $violationList = new ConstraintViolationList();
                $violationList->add(new ConstraintViolation(
                    $e->getMessage(),
                    $e->getMessage(),
                    [],
                    null,
                    '',
                    null
                ));
                throw HttpException::fromStatusCode($attribute->validationFailedStatusCode, $e->getMessage(), new ValidationFailedException(null, $violationList));
            }

            // Check if the array of objects contains types we are not expecting.
            $unsupportedDataTypes = array_filter(
                is_array($payload) ? $payload : [$payload],
                function (object $object) use ($attribute): bool {
                    return !($object instanceof AttributesDTOInterface && $object->supports($attribute->resourceType));
                }
            );

            if (count($unsupportedDataTypes)) {
                throw HttpException::fromStatusCode($attribute->validationFailedStatusCode, sprintf('Unsupported data types: "%s"', implode(', ', array_map(fn (AttributesDTOInterface $object) => ResourceKeyGenerator::generateResourceKey($object->getResourceType()), $unsupportedDataTypes))));
            }

            return $payload;
        } catch (UnsupportedFormatException $e) {
            throw new UnsupportedMediaTypeHttpException(\sprintf('Unsupported format: "%s".', $format), $e);
        } catch (NotEncodableValueException $e) {
            throw new BadRequestHttpException(\sprintf('Request payload contains invalid "%s" data.', $format), $e);
        } catch (UnexpectedPropertyException $e) {
            throw new BadRequestHttpException(\sprintf('Request payload contains invalid "%s" property.', $e->property), $e);
        }
    }

    private function extractAttributes(object $item, MapAttributePayload $attribute): object
    {
        if (!property_exists($item, 'attributes')) {
            throw HttpException::fromStatusCode($attribute->validationFailedStatusCode, 'data.attributes key is missing.');
        }

        $attributes = $item->attributes;
        if (!is_object($attributes)) {
            throw HttpException::fromStatusCode($attribute->validationFailedStatusCode, 'data.attributes should be an object.');
        }

        $attributes->id = $item->id ?? null;
        $attributes->type = $item->type ?? null;

        return $attributes;
    }

    private function mapRelationshipsPayload(Request $request, ArgumentMetadata $argument, MapRelationshipPayload $attribute): object|array|null
    {
        if (null === $format = $request->getContentTypeFormat()) {
            throw new UnsupportedMediaTypeHttpException('Unsupported format.');
        }

        if ($attribute->acceptFormat !== null && !\in_array($format, (array) $attribute->acceptFormat, true)) {
            throw new UnsupportedMediaTypeHttpException(\sprintf('Unsupported format, expects "%s", but "%s" given.', implode('", "', (array) $attribute->acceptFormat), $format));
        }

        if ($argument->getType() === 'array' && $attribute->type !== null) {
            $isArray = true;
            $type = $attribute->type.'[]';
        } else {
            $isArray = false;
            $type = (string) $argument->getType();
        }

        if ('' === ($data = $request->getContent()) && ($argument->isNullable() || $argument->hasDefaultValue())) {
            return null;
        }

        if ($format === 'form') {
            throw new BadRequestHttpException('Request payload contains invalid "form" data.');
        }

        try {
            /** @var ?object $content */
            $content = json_decode($data, false);
            if ($content === null) {
                throw HttpException::fromStatusCode($attribute->validationFailedStatusCode, json_last_error_msg());
            }

            if (!property_exists($content, 'data')) {
                throw HttpException::fromStatusCode($attribute->validationFailedStatusCode, '"data" key is missing.');
            }

            /** @psalm-suppress MixedAssignment */
            $data = $content->data;
            if (!is_object($data)) {
                throw HttpException::fromStatusCode($attribute->validationFailedStatusCode, '"data attribute is missing data.');
            }

            $relationships = null;
            if (!property_exists($data, 'relationships')) {
                // If we're just dealing with a POST to a relationship endpoint then
                // we should only have data structured like:
                // { 'data': { 'id': 'abc', 'type': 'some-type' }}
                $extracted = $this->extractRelationships($data, $attribute);
                if (count($extracted) === 0) {
                    throw HttpException::fromStatusCode($attribute->validationFailedStatusCode, '"data.relationships attribute is missing.');
                }

                $relationships = $extracted;
            }

            // If we're trying to extract the relationships from a complete jsonapi request
            // that contains 'data' and 'relationships' attributes then we can parse that here.
            if ($relationships === null) {
                /** @var array|object|null $relationships */
                $relationships = $data->relationships;
                if ($isArray && is_array($relationships)) {
                    /** @var object[] $relationships */
                    $relationships = array_map(function (object $item) use ($attribute): array {
                        return $this->extractRelationships($item, $attribute);
                    }, $relationships);
                } elseif (is_object($relationships)) {
                    $relationships = $this->extractRelationships($relationships, $attribute);
                } else {
                    throw HttpException::fromStatusCode($attribute->validationFailedStatusCode, '"relationships" needs to be an object or an array.');
                }
            }

            $dataToEncode = $relationships + ['context' => (array) $content];

            $dataToDeserialise = json_encode($dataToEncode);

            /** @var AttributesDTOInterface|AttributesDTOInterface[] $payload */
            $payload = $this->serializer->deserialize($dataToDeserialise, $type, $format, self::CONTEXT_DESERIALIZE + $attribute->serializationContext);

            return $payload;
        } catch (UnsupportedFormatException $e) {
            throw new UnsupportedMediaTypeHttpException(\sprintf('Unsupported format: "%s".', $format), $e);
        } catch (NotEncodableValueException $e) {
            throw new BadRequestHttpException(\sprintf('Request payload contains invalid "%s" data.', $format), $e);
        } catch (UnexpectedPropertyException $e) {
            throw new BadRequestHttpException(\sprintf('Request payload contains invalid "%s" property.', $e->property), $e);
        }
    }

    private function extractRelationships(object $item, MapRelationshipPayload $attribute): array
    {
        $relationships = [];

        $items = (array) $item;
        $keys = array_keys($items);
        sort($keys);
        if (count($keys) == 2 && $keys === ['id', 'type']) {
            return ['id' => $items['id']];
        }

        /**
         * @var string $key
         * @var mixed $data
         */
        foreach ($items as $key => $data) {
            if ($data === null) {
                $relationships[$key] = null;
                continue;
            }

            if (is_object($data) && property_exists($data, 'data')) {
                $relationships[$key] = is_object($data->data) && property_exists($data->data, 'id')
                    ? (string) $data->data->id
                    : null;
            }
        }

        return $relationships;
    }
}
