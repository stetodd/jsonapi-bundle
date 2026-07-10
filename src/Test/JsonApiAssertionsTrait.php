<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle\Test;

use PHPUnit\Framework\Assert;

/**
 * Reusable assertions for JSON:API document shapes (https://jsonapi.org/format/#document-structure),
 * so every resource gets conformance coverage cheaply. Use from any PHPUnit test:
 *
 *     $this->assertJsonApiDocument($browser->json()->decoded());
 *
 * These validate structure, not content — pair them with normal assertions about
 * your data.
 */
trait JsonApiAssertionsTrait
{
    /**
     * A top-level document: at least one of data/errors/meta, never data alongside
     * errors, and included only accompanying data.
     */
    public static function assertJsonApiDocument(mixed $document): void
    {
        Assert::assertIsArray($document, 'A JSON:API document must be an object.');
        Assert::assertNotEmpty(
            array_intersect(['data', 'errors', 'meta'], array_keys($document)),
            'A document must contain at least one of: data, errors, meta.',
        );
        Assert::assertFalse(
            array_key_exists('data', $document) && array_key_exists('errors', $document),
            'The members data and errors must not coexist in a document.',
        );

        if (array_key_exists('data', $document)) {
            self::assertJsonApiPrimaryData($document['data']);
        }

        if (array_key_exists('included', $document)) {
            Assert::assertArrayHasKey('data', $document, 'included must not be present without data.');
            Assert::assertIsArray($document['included']);
            Assert::assertTrue(array_is_list($document['included']), 'included must be an array of resource objects.');
            foreach ($document['included'] as $resource) {
                self::assertJsonApiResourceObject($resource);
            }
        }
    }

    /**
     * Primary data: a resource object, a resource identifier, null, or an array of
     * either.
     */
    public static function assertJsonApiPrimaryData(mixed $data): void
    {
        if ($data === null) {
            return;
        }

        Assert::assertIsArray($data, 'Primary data must be null, an object or an array.');

        if (array_is_list($data)) {
            foreach ($data as $resource) {
                self::assertJsonApiResourceObject($resource);
            }

            return;
        }

        self::assertJsonApiResourceObject($data);
    }

    /**
     * A resource object (or bare resource identifier): string type + id, attributes
     * without id/type collisions, well-formed relationships and links.
     */
    public static function assertJsonApiResourceObject(mixed $resource, ?string $expectedType = null): void
    {
        Assert::assertIsArray($resource, 'A resource object must be an object.');
        Assert::assertArrayHasKey('type', $resource);
        Assert::assertArrayHasKey('id', $resource);
        Assert::assertIsString($resource['type'], 'A resource type must be a string.');
        Assert::assertNotSame('', $resource['type']);
        Assert::assertIsString($resource['id'], 'A resource id must be a string.');

        if ($expectedType !== null) {
            Assert::assertSame($expectedType, $resource['type']);
        }

        if (array_key_exists('attributes', $resource) && is_array($resource['attributes'])) {
            Assert::assertArrayNotHasKey('id', $resource['attributes'], 'attributes must not contain an id member.');
            Assert::assertArrayNotHasKey('type', $resource['attributes'], 'attributes must not contain a type member.');
        }

        if (array_key_exists('relationships', $resource)) {
            Assert::assertIsArray($resource['relationships']);
            foreach ($resource['relationships'] as $name => $relationship) {
                Assert::assertIsString($name);
                self::assertJsonApiRelationshipObject($relationship, $name);
            }
        }

        if (array_key_exists('links', $resource)) {
            Assert::assertIsArray($resource['links']);
            if (array_key_exists('self', $resource['links'])) {
                Assert::assertIsString($resource['links']['self']);
            }
        }
    }

    /**
     * A relationship object: at least one of links/data/meta; links carry
     * self/related strings; data is resource linkage.
     */
    public static function assertJsonApiRelationshipObject(mixed $relationship, string $name = ''): void
    {
        $label = $name !== '' ? sprintf(' "%s"', $name) : '';

        Assert::assertIsArray($relationship, sprintf('Relationship%s must be an object.', $label));
        Assert::assertNotEmpty(
            array_intersect(['links', 'data', 'meta'], array_keys($relationship)),
            sprintf('Relationship%s must contain at least one of: links, data, meta.', $label),
        );

        if (array_key_exists('links', $relationship)) {
            Assert::assertIsArray($relationship['links']);
            foreach (['self', 'related'] as $link) {
                if (array_key_exists($link, $relationship['links'])) {
                    Assert::assertIsString($relationship['links'][$link]);
                }
            }
        }

        if (array_key_exists('data', $relationship)) {
            self::assertJsonApiResourceLinkage($relationship['data']);
        }
    }

    /**
     * Resource linkage: null, a {type, id} identifier, or a list of identifiers.
     */
    public static function assertJsonApiResourceLinkage(mixed $data): void
    {
        if ($data === null) {
            return;
        }

        Assert::assertIsArray($data, 'Resource linkage must be null, an object or an array.');

        $identifiers = array_is_list($data) ? $data : [$data];
        foreach ($identifiers as $identifier) {
            Assert::assertIsArray($identifier);
            Assert::assertArrayHasKey('type', $identifier);
            Assert::assertArrayHasKey('id', $identifier);
            Assert::assertIsString($identifier['type']);
            Assert::assertIsString($identifier['id']);
        }
    }

    /**
     * An error document: a non-empty errors list of error objects, each with a
     * string status when present and a source containing only pointer/parameter/header.
     */
    public static function assertJsonApiErrorDocument(mixed $document): void
    {
        Assert::assertIsArray($document);
        Assert::assertArrayHasKey('errors', $document);
        Assert::assertArrayNotHasKey('data', $document, 'The members data and errors must not coexist.');
        Assert::assertIsArray($document['errors']);
        Assert::assertTrue(array_is_list($document['errors']), 'errors must be an array of error objects.');
        Assert::assertNotEmpty($document['errors']);

        foreach ($document['errors'] as $error) {
            Assert::assertIsArray($error);
            Assert::assertNotEmpty(
                array_intersect(['id', 'links', 'status', 'code', 'title', 'detail', 'source', 'meta'], array_keys($error)),
                'An error object must contain at least one spec member.',
            );

            if (array_key_exists('status', $error)) {
                Assert::assertIsString($error['status'], 'An error status must be a string.');
            }

            if (array_key_exists('source', $error)) {
                Assert::assertIsArray($error['source']);
                Assert::assertEmpty(
                    array_diff(array_keys($error['source']), ['pointer', 'parameter', 'header']),
                    'An error source may only contain pointer, parameter or header.',
                );
            }
        }
    }

    /**
     * Collection pagination links: self/first/last present as strings; next/prev,
     * when present, strings or null.
     */
    public static function assertJsonApiPaginationLinks(mixed $document): void
    {
        Assert::assertIsArray($document);
        Assert::assertArrayHasKey('links', $document);
        Assert::assertIsArray($document['links']);

        foreach (['self', 'first', 'last'] as $link) {
            Assert::assertArrayHasKey($link, $document['links']);
            Assert::assertIsString($document['links'][$link]);
        }

        foreach (['next', 'prev'] as $link) {
            if (array_key_exists($link, $document['links']) && $document['links'][$link] !== null) {
                Assert::assertIsString($document['links'][$link]);
            }
        }
    }
}
