<?php

/**
 * wp-jsonapi: JSON:API 1.1 on the WordPress REST API.
 * Copyright (C) 2026 Polunich
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Polunich\WpJsonApi\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Document\AtomicResultsDocument;
use Polunich\WpJsonApi\Document\DataDocument;
use Polunich\WpJsonApi\Document\DocumentEncoder;
use Polunich\WpJsonApi\Document\ErrorDocument;
use Polunich\WpJsonApi\Document\ErrorObject;
use Polunich\WpJsonApi\Document\ErrorSource;
use Polunich\WpJsonApi\Document\Included;
use Polunich\WpJsonApi\Document\JsonApiObject;
use Polunich\WpJsonApi\Document\Link;
use Polunich\WpJsonApi\Document\Links;
use Polunich\WpJsonApi\Document\MetaDocument;
use Polunich\WpJsonApi\Document\RelationshipObject;
use Polunich\WpJsonApi\Document\ResourceIdentifierObject;
use Polunich\WpJsonApi\Document\ResourceObject;

/**
 * @internal
 */
#[CoversClass(DocumentEncoder::class)]
final class DocumentEncoderTest extends TestCase
{
    public function testTheMembersOfADataDocumentAreInAFixedOrder(): void
    {
        $document = new DataDocument(
            self::pet(),
            new JsonApiObject(),
            new Links(['self' => 'http://example.com/pets/1']),
            new Included([self::customer()]),
            ['page' => ['total' => 1]],
        );

        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets/1"},'
            . '"data":{"type":"pets","id":"1","attributes":{"name":"Rex"},'
            . '"relationships":{"owner":{"links":{"self":"http://example.com/pets/1/relationships/owner",'
            . '"related":"http://example.com/pets/1/owner"},"data":{"type":"customers","id":"9"}}},'
            . '"links":{"self":"http://example.com/pets/1"}},'
            . '"included":[{"type":"customers","id":"9","links":{"self":"http://example.com/customers/9"}}],'
            . '"meta":{"page":{"total":1}}}',
            self::json($document),
        );
    }

    public function testTheMembersOfAResourceObjectAreInAFixedOrder(): void
    {
        $encoded = (new DocumentEncoder())->encode(new DataDocument(
            new ResourceObject(
                'pets',
                '1',
                new Links(['self' => 'http://example.com/pets/1']),
                ['name' => 'Rex'],
                ['owner' => self::owner()],
                ['version' => 3],
            ),
            new JsonApiObject(),
            new Links(['self' => 'http://example.com/pets/1']),
        ));

        self::assertStringContainsString(
            '"data":{"type":"pets","id":"1","attributes":{"name":"Rex"},"relationships":{"owner":',
            (string) json_encode($encoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        self::assertStringContainsString(
            '"links":{"self":"http://example.com/pets/1"},"meta":{"version":3}}',
            (string) json_encode($encoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    public function testPrimaryDataOfNullIsWritten(): void
    {
        $document = new DataDocument(
            null,
            new JsonApiObject(),
            new Links(['self' => 'http://example.com/pets/1/owner']),
        );

        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets/1/owner"},"data":null}',
            self::json($document),
        );
    }

    public function testAListOfResourceObjectsIsWrittenInFull(): void
    {
        $document = new DataDocument(
            [self::pet(), self::pet('2', 'Whiskers')],
            new JsonApiObject(),
            new Links(['self' => 'http://example.com/pets']),
            new Included([self::customer()]),
        );

        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets"},"data":['
            . '{"type":"pets","id":"1","attributes":{"name":"Rex"},'
            . '"relationships":{"owner":{"links":{"self":"http://example.com/pets/1/relationships/owner",'
            . '"related":"http://example.com/pets/1/owner"},"data":{"type":"customers","id":"9"}}},'
            . '"links":{"self":"http://example.com/pets/1"}},'
            . '{"type":"pets","id":"2","attributes":{"name":"Whiskers"},'
            . '"relationships":{"owner":{"links":{"self":"http://example.com/pets/2/relationships/owner",'
            . '"related":"http://example.com/pets/2/owner"},"data":{"type":"customers","id":"9"}}},'
            . '"links":{"self":"http://example.com/pets/2"}}],'
            . '"included":[{"type":"customers","id":"9","links":{"self":"http://example.com/customers/9"}}]}',
            self::json($document),
        );
    }

    public function testAnEmptyIncludedIsWrittenAsAnEmptyArray(): void
    {
        $document = new DataDocument(
            [],
            new JsonApiObject(),
            new Links(['self' => 'http://example.com/pets']),
            new Included(),
        );

        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets"},"data":[],"included":[]}',
            self::json($document),
        );
    }

    public function testAnEmptyMetaIsLeftOut(): void
    {
        $document = new DataDocument(
            null,
            new JsonApiObject(),
            new Links(['self' => 'http://example.com/pets/1']),
            null,
            [],
        );

        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets/1"},"data":null}',
            self::json($document),
        );
    }

    public function testEmptyAttributesAndRelationshipsAreLeftOut(): void
    {
        $document = new DataDocument(
            self::customer(),
            new JsonApiObject(),
            new Links(['self' => 'http://example.com/customers/9']),
        );

        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/customers/9"},'
            . '"data":{"type":"customers","id":"9","links":{"self":"http://example.com/customers/9"}}}',
            self::json($document),
        );
    }

    /**
     * one case per member set, since each set has a cast of its own.
     * attributes and relationships share a namespace, so each set gets its own document:
     * one document cannot name both sets `0`, `1`, and `2`, `3` form no list.
     * every links object goes through the same cast, so the links of an error object stand for all.
     * each case compares the whole document, since a substring could match a different member
     * and outlive the loss of the cast under test
     */
    #[DataProvider('memberSetsWhoseNamesFormAList')]
    public function testAMemberSetWhoseNamesFormAListStaysAnObject(
        DataDocument|ErrorDocument $document,
        string $expected,
    ): void {
        self::assertSame($expected, self::json($document));
    }

    /**
     * @return iterable<string, array{DataDocument|ErrorDocument, string}>
     */
    public static function memberSetsWhoseNamesFormAList(): iterable
    {
        yield 'attributes' => [
            new DataDocument(
                new ResourceObject(
                    'pets',
                    '1',
                    self::selfLink(),
                    self::members(['0', '1']),
                    self::relationships(['a', 'b']),
                ),
                new JsonApiObject(),
                self::selfLink(),
            ),
            '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets/1"},'
            . '"data":{"type":"pets","id":"1","attributes":{"0":"0","1":"1"},'
            . '"relationships":{"a":{"links":{"self":"http://example.com/pets/1/relationships/a",'
            . '"related":"http://example.com/pets/1/a"}},'
            . '"b":{"links":{"self":"http://example.com/pets/1/relationships/b",'
            . '"related":"http://example.com/pets/1/b"}}},'
            . '"links":{"self":"http://example.com/pets/1"}}}',
        ];

        yield 'relationships' => [
            new DataDocument(
                new ResourceObject(
                    'pets',
                    '1',
                    self::selfLink(),
                    self::members(['a', 'b']),
                    self::relationships(['0', '1']),
                ),
                new JsonApiObject(),
                self::selfLink(),
            ),
            '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets/1"},'
            . '"data":{"type":"pets","id":"1","attributes":{"a":"a","b":"b"},'
            . '"relationships":{"0":{"links":{"self":"http://example.com/pets/1/relationships/0",'
            . '"related":"http://example.com/pets/1/0"}},'
            . '"1":{"links":{"self":"http://example.com/pets/1/relationships/1",'
            . '"related":"http://example.com/pets/1/1"}}},'
            . '"links":{"self":"http://example.com/pets/1"}}}',
        ];

        yield 'the links of an error object' => [
            new ErrorDocument(
                [new ErrorObject(
                    '400',
                    'invalid_query_parameter',
                    'the query parameter is invalid',
                    links: new Links(self::members(['0', '1'])),
                )],
                new JsonApiObject(),
            ),
            '{"jsonapi":{"version":"1.1"},"errors":[{"status":"400","code":"invalid_query_parameter",'
            . '"title":"the query parameter is invalid","links":{"0":"0","1":"1"}}]}',
        ];
    }

    /**
     * each meta site has a cast of its own, so each gets a case of its own;
     * the meta of a meta document, written even when empty, is tested apart
     */
    #[DataProvider('metaWhoseNamesFormAList')]
    public function testEveryMetaMemberIsWrittenAsAnObject(
        AtomicResultsDocument|DataDocument|ErrorDocument $document,
        string $expected,
    ): void {
        self::assertSame($expected, self::json($document));
    }

    /**
     * @return iterable<string, array{AtomicResultsDocument|DataDocument|ErrorDocument, string}>
     */
    public static function metaWhoseNamesFormAList(): iterable
    {
        yield from self::sites(self::members(['0', '1']), [
            'data document' => '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets/1"},"data":null,'
                . '"meta":{"0":"0","1":"1"}}',
            'error document' => '{"jsonapi":{"version":"1.1"},"errors":[{"status":"404","code":"not_found",'
                . '"title":"not found"}],"meta":{"0":"0","1":"1"}}',
            'atomic:results document' => '{"jsonapi":{"version":"1.1","ext":["https://jsonapi.org/ext/atomic"]},'
                . '"atomic:results":[{}],"meta":{"0":"0","1":"1"}}',
            'resource object' => '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets/1"},'
                . '"data":{"type":"pets","id":"1","links":{"self":"http://example.com/pets/1"},'
                . '"meta":{"0":"0","1":"1"}}}',
            'resource identifier object' => '{"jsonapi":{"version":"1.1"},'
                . '"links":{"self":"http://example.com/pets/1/relationships/owner"},'
                . '"data":{"type":"customers","id":"9","meta":{"0":"0","1":"1"}}}',
            'relationship object' => '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets/1"},'
                . '"data":{"type":"pets","id":"1",'
                . '"relationships":{"owner":{"links":{'
                . '"self":"http://example.com/pets/1/relationships/owner",'
                . '"related":"http://example.com/pets/1/owner"},"data":null,'
                . '"meta":{"0":"0","1":"1"}}},"links":{"self":"http://example.com/pets/1"}}}',
            'link object' => '{"jsonapi":{"version":"1.1"},"links":{"self":{"href":"http://example.com/pets/1",'
                . '"title":"the pet","meta":{"0":"0","1":"1"}}},"data":null}',
            'error object' => '{"jsonapi":{"version":"1.1"},"errors":[{"status":"404","code":"not_found","title":"not'
                . ' found","meta":{"0":"0","1":"1"}}]}',
        ]);
    }

    #[DataProvider('anEmptyMeta')]
    public function testEveryEmptyMetaIsLeftOut(
        AtomicResultsDocument|DataDocument|ErrorDocument $document,
        string $expected,
    ): void {
        self::assertSame($expected, self::json($document));
    }

    /**
     * @return iterable<string, array{AtomicResultsDocument|DataDocument|ErrorDocument, string}>
     */
    public static function anEmptyMeta(): iterable
    {
        yield from self::sites([], [
            'data document' => '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets/1"},"data":null}',
            'error document' => '{"jsonapi":{"version":"1.1"},"errors":[{"status":"404","code":"not_found",'
                . '"title":"not found"}]}',
            'atomic:results document' => '{"jsonapi":{"version":"1.1","ext":["https://jsonapi.org/ext/atomic"]},'
                . '"atomic:results":[{}]}',
            'resource object' => '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets/1"},'
                . '"data":{"type":"pets","id":"1","links":{"self":"http://example.com/pets/1"}}}',
            'resource identifier object' => '{"jsonapi":{"version":"1.1"},'
                . '"links":{"self":"http://example.com/pets/1/relationships/owner"},'
                . '"data":{"type":"customers","id":"9"}}',
            'relationship object' => '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets/1"},'
                . '"data":{"type":"pets","id":"1",'
                . '"relationships":{"owner":{"links":{'
                . '"self":"http://example.com/pets/1/relationships/owner",'
                . '"related":"http://example.com/pets/1/owner"},"data":null}},'
                . '"links":{"self":"http://example.com/pets/1"}}}',
            'link object' => '{"jsonapi":{"version":"1.1"},"links":{"self":{"href":"http://example.com/pets/1",'
                . '"title":"the pet"}},"data":null}',
            'error object' => '{"jsonapi":{"version":"1.1"},"errors":[{"status":"404","code":"not_found","title":"not'
                . ' found"}]}',
        ]);
    }

    public function testAResourceIdentifierObjectCarriesTypeIdAndMeta(): void
    {
        $document = new DataDocument(
            new ResourceIdentifierObject('customers', '9', ['role' => 'owner']),
            new JsonApiObject(),
            new Links(['self' => 'http://example.com/pets/1/relationships/owner']),
        );

        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets/1/relationships/owner"},'
            . '"data":{"type":"customers","id":"9","meta":{"role":"owner"}}}',
            self::json($document),
        );
    }

    public function testTheMembersOfARelationshipObjectAreInAFixedOrder(): void
    {
        $relationship = RelationshipObject::toMany(
            new Links([
                'self' => 'http://example.com/pets/1/relationships/orders',
                'related' => 'http://example.com/pets/1/orders',
            ]),
            [new ResourceIdentifierObject('orders', '5')],
            ['count' => 1],
        );

        $document = new DataDocument(
            new ResourceObject(
                'pets',
                '1',
                new Links(['self' => 'http://example.com/pets/1']),
                [],
                ['orders' => $relationship],
            ),
            new JsonApiObject(),
            new Links(['self' => 'http://example.com/pets/1']),
        );

        self::assertStringContainsString(
            '"orders":{"links":{"self":"http://example.com/pets/1/relationships/orders",'
            . '"related":"http://example.com/pets/1/orders"},"data":[{"type":"orders","id":"5"}],"meta":{"count":1}}',
            self::json($document),
        );
    }

    /**
     * absent linkage and the four forms the specification gives present linkage.
     * each case compares the whole document: the absent state is a missing member,
     * and a substring check cannot tell it from the `data` member the primary data writes
     */
    #[DataProvider('statesOfResourceLinkage')]
    public function testEveryStateOfResourceLinkageIsWrittenAsItsOwnForm(DataDocument $document, string $expected): void
    {
        self::assertSame($expected, self::json($document));
    }

    /**
     * @return iterable<string, array{DataDocument, string}>
     */
    public static function statesOfResourceLinkage(): iterable
    {
        yield 'absent linkage' => [
            self::documentWithRelationship('orders', RelationshipObject::withoutLinkage(self::ordersLinks())),
            self::documentWithRelationshipAsJson(
                '"orders":{"links":{"self":"http://example.com/pets/1/relationships/orders",'
                . '"related":"http://example.com/pets/1/orders"}}',
            ),
        ];

        yield 'an empty to-one relationship' => [
            self::documentWithRelationship('owner', RelationshipObject::toOne(self::ownerLinks(), null)),
            self::documentWithRelationshipAsJson(
                '"owner":{"links":{"self":"http://example.com/pets/1/relationships/owner",'
                . '"related":"http://example.com/pets/1/owner"},"data":null}',
            ),
        ];

        yield 'a non-empty to-one relationship' => [
            self::documentWithRelationship(
                'owner',
                RelationshipObject::toOne(self::ownerLinks(), new ResourceIdentifierObject('customers', '9')),
            ),
            self::documentWithRelationshipAsJson(
                '"owner":{"links":{"self":"http://example.com/pets/1/relationships/owner",'
                . '"related":"http://example.com/pets/1/owner"},"data":{"type":"customers","id":"9"}}',
            ),
        ];

        yield 'an empty to-many relationship' => [
            self::documentWithRelationship('orders', RelationshipObject::toMany(self::ordersLinks(), [])),
            self::documentWithRelationshipAsJson(
                '"orders":{"links":{"self":"http://example.com/pets/1/relationships/orders",'
                . '"related":"http://example.com/pets/1/orders"},"data":[]}',
            ),
        ];

        yield 'a non-empty to-many relationship' => [
            self::documentWithRelationship('orders', RelationshipObject::toMany(self::ordersLinks(), [
                new ResourceIdentifierObject('orders', '5'),
                new ResourceIdentifierObject('orders', '12'),
            ])),
            self::documentWithRelationshipAsJson(
                '"orders":{"links":{"self":"http://example.com/pets/1/relationships/orders",'
                . '"related":"http://example.com/pets/1/orders"},'
                . '"data":[{"type":"orders","id":"5"},{"type":"orders","id":"12"}]}',
            ),
        ];
    }

    public function testALinkWithOnlyAnHrefIsWrittenAsAString(): void
    {
        $document = new DataDocument(
            null,
            new JsonApiObject(),
            new Links(['self' => new Link('http://example.com/pets/1')]),
        );

        self::assertStringContainsString('"links":{"self":"http://example.com/pets/1"}', self::json($document));
    }

    public function testTheMembersOfALinkObjectAreInAFixedOrder(): void
    {
        $link = new Link(
            'http://example.com/pets/1/orders',
            rel: 'related',
            describedby: new Link('http://example.com/schemas/pet-orders'),
            title: 'Orders',
            type: 'application/vnd.api+json',
            hreflang: ['en', 'de'],
            meta: ['count' => 10],
        );

        $document = new DataDocument(
            null,
            new JsonApiObject(),
            new Links(['self' => 'http://example.com/pets/1', 'related' => $link]),
        );

        self::assertStringContainsString(
            '"related":{"href":"http://example.com/pets/1/orders","rel":"related",'
            . '"describedby":"http://example.com/schemas/pet-orders","title":"Orders",'
            . '"type":"application/vnd.api+json","hreflang":["en","de"],"meta":{"count":10}}',
            self::json($document),
        );
    }

    public function testAnEmptyHreflangIsLeftOut(): void
    {
        $document = new DataDocument(
            null,
            new JsonApiObject(),
            new Links([
                'self' => new Link('http://example.com/pets/1', hreflang: []),
                'related' => new Link('http://example.com/pets/1/owner', title: 'the owner', hreflang: [], meta: []),
            ]),
        );

        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets/1",'
            . '"related":{"href":"http://example.com/pets/1/owner","title":"the owner"}},"data":null}',
            self::json($document),
        );
    }

    public function testALinkThatDoesNotExistIsWrittenAsNull(): void
    {
        $document = new DataDocument(
            [],
            new JsonApiObject(),
            new Links([
                'self' => 'http://example.com/pets',
                'prev' => null,
                'next' => 'http://example.com/pets?page[after]=9',
            ]),
        );

        self::assertStringContainsString(
            '"prev":null,"next":"http://example.com/pets?page[after]=9"',
            self::json($document),
        );
    }

    public function testTheJsonApiObjectLeavesOutEmptyExtAndProfile(): void
    {
        $document = new DataDocument(null, new JsonApiObject(), new Links(['self' => 'http://example.com/pets/1']));

        self::assertStringStartsWith('{"jsonapi":{"version":"1.1"},', self::json($document));
    }

    public function testTheAppliedExtensionsAndProfilesAreWrittenAfterTheVersion(): void
    {
        $jsonApi = new JsonApiObject(
            ext: ['https://jsonapi.org/ext/atomic'],
            profile: ['http://example.com/profiles/versioning'],
        );

        $document = new DataDocument(null, $jsonApi, new Links(['self' => 'http://example.com/pets/1']));

        self::assertStringStartsWith(
            '{"jsonapi":{"version":"1.1","ext":["https://jsonapi.org/ext/atomic"],'
            . '"profile":["http://example.com/profiles/versioning"]},',
            self::json($document),
        );
    }

    /**
     * `{}`, not `[]`: an empty PHP array would encode as a JSON array
     */
    public function testAResultWithoutMembersIsWrittenAsAnEmptyObject(): void
    {
        $document = new AtomicResultsDocument(
            [null, self::pet()],
            new JsonApiObject(ext: ['https://jsonapi.org/ext/atomic']),
        );

        self::assertSame(
            '{"jsonapi":{"version":"1.1","ext":["https://jsonapi.org/ext/atomic"]},"atomic:results":[{},'
            . '{"data":{"type":"pets","id":"1","attributes":{"name":"Rex"},'
            . '"relationships":{"owner":{"links":{"self":"http://example.com/pets/1/relationships/owner",'
            . '"related":"http://example.com/pets/1/owner"},"data":{"type":"customers","id":"9"}}},'
            . '"links":{"self":"http://example.com/pets/1"}}}]}',
            self::json($document),
        );
    }

    public function testTheMetaOfAnAtomicResultsDocumentComesLast(): void
    {
        $document = new AtomicResultsDocument(
            [null],
            new JsonApiObject(ext: ['https://jsonapi.org/ext/atomic']),
            ['took' => 12],
        );

        self::assertStringEndsWith('"atomic:results":[{}],"meta":{"took":12}}', self::json($document));
    }

    public function testAMetaDocumentIsTheJsonApiObjectAndMeta(): void
    {
        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"meta":{"pets":3,"customers":2}}',
            self::json(new MetaDocument(['pets' => 3, 'customers' => 2], new JsonApiObject())),
        );
    }

    public function testTheMetaOfAMetaDocumentIsWrittenEvenEmptyAndAsAnObject(): void
    {
        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"meta":{}}',
            self::json(new MetaDocument([], new JsonApiObject())),
        );
        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"meta":{"0":"a","1":"b"}}',
            self::json(new MetaDocument(['a', 'b'], new JsonApiObject())),
        );
    }

    public function testTheMembersOfAnErrorDocumentAreInAFixedOrder(): void
    {
        $document = new ErrorDocument(
            [new ErrorObject(
                '422',
                'invalid_attribute',
                'the attribute is invalid',
                'the name must not be empty',
                ErrorSource::pointer('/data/attributes/name'),
                new Links(['type' => 'http://example.com/errors/invalid-attribute']),
                ['field' => 'name'],
            )],
            new JsonApiObject(),
            ['requestId' => 'abc'],
        );

        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"errors":[{"status":"422","code":"invalid_attribute",'
            . '"title":"the attribute is invalid","detail":"the name must not be empty",'
            . '"source":{"pointer":"/data/attributes/name"},'
            . '"links":{"type":"http://example.com/errors/invalid-attribute"},"meta":{"field":"name"}}],'
            . '"meta":{"requestId":"abc"}}',
            self::json($document),
        );
    }

    public function testAnErrorObjectWithoutOptionalMembersCarriesStatusCodeAndTitle(): void
    {
        $document = new ErrorDocument([new ErrorObject('404', 'not_found', 'not found')], new JsonApiObject());

        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"errors":[{"status":"404","code":"not_found",'
            . '"title":"not found"}]}',
            self::json($document),
        );
    }

    public function testAnEmptyLinksObjectOfADocumentAResourceAndARelationshipIsLeftOut(): void
    {
        $document = new DataDocument(
            new ResourceObject(
                'pets',
                '1',
                new Links([]),
                [],
                ['owner' => RelationshipObject::toOne(new Links([]), new ResourceIdentifierObject('customers', '9'))],
            ),
            new JsonApiObject(),
            new Links([]),
        );

        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"data":{"type":"pets","id":"1","relationships":{"owner":{"data":'
            . '{"type":"customers","id":"9"}}}}}',
            self::json($document),
        );
    }

    public function testARelationshipObjectWithMetaAloneIsWrittenWithIt(): void
    {
        self::assertStringContainsString(
            '"relationships":{"owner":{"meta":{"count":0}}}',
            self::json(self::documentWithRelationship(
                'owner',
                RelationshipObject::withoutLinkage(new Links([]), ['count' => 0]),
            )),
        );
    }

    public function testAnEmptyLinksOfAnErrorObjectIsLeftOut(): void
    {
        $document = new ErrorDocument(
            [new ErrorObject('404', 'not_found', 'not found', links: new Links([]))],
            new JsonApiObject(),
        );

        self::assertStringNotContainsString('"links"', self::json($document));
    }

    public function testTheSourceOfAnErrorObjectCarriesTheMemberItWasBuiltWith(): void
    {
        $document = new ErrorDocument(
            [
                new ErrorObject(
                    '400',
                    'invalid_query_parameter',
                    'the query parameter is invalid',
                    source: ErrorSource::parameter('sort'),
                ),
                new ErrorObject(
                    '412',
                    'precondition_failed',
                    'precondition failed',
                    source: ErrorSource::header('If-Match'),
                ),
            ],
            new JsonApiObject(),
        );

        $json = self::json($document);

        self::assertStringContainsString('"source":{"parameter":"sort"}', $json);
        self::assertStringContainsString('"source":{"header":"If-Match"}', $json);
    }

    public function testTheSameDocumentAlwaysProducesTheSameOutput(): void
    {
        $document = new DataDocument(
            self::pet(),
            new JsonApiObject(),
            new Links(['self' => 'http://example.com/pets/1']),
            new Included([self::customer()]),
        );

        self::assertSame(self::json($document), self::json($document));
    }

    private static function documentWithRelationship(string $name, RelationshipObject $relationship): DataDocument
    {
        return new DataDocument(
            new ResourceObject('pets', '1', self::selfLink(), [], [$name => $relationship]),
            new JsonApiObject(),
            self::selfLink(),
        );
    }

    /**
     * the JSON of `documentWithRelationship()`,
     * with `$relationship` as the one member of `relationships`
     */
    private static function documentWithRelationshipAsJson(string $relationship): string
    {
        return '{"jsonapi":{"version":"1.1"},"links":{"self":"http://example.com/pets/1"},'
            . '"data":{"type":"pets","id":"1","relationships":{' . $relationship . '},'
            . '"links":{"self":"http://example.com/pets/1"}}}';
    }

    private static function ordersLinks(): Links
    {
        return new Links([
            'self' => 'http://example.com/pets/1/relationships/orders',
            'related' => 'http://example.com/pets/1/orders',
        ]);
    }

    private static function json(AtomicResultsDocument|DataDocument|ErrorDocument|MetaDocument $document): string
    {
        return (string) json_encode(
            (new DocumentEncoder())->encode($document),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private static function pet(string $id = '1', string $name = 'Rex'): ResourceObject
    {
        return new ResourceObject(
            'pets',
            $id,
            new Links(['self' => 'http://example.com/pets/' . $id]),
            ['name' => $name],
            ['owner' => self::owner($id)],
        );
    }

    private static function owner(string $petId = '1'): RelationshipObject
    {
        return RelationshipObject::toOne(self::ownerLinks($petId), new ResourceIdentifierObject('customers', '9'));
    }

    private static function selfLink(): Links
    {
        return new Links(['self' => 'http://example.com/pets/1']);
    }

    private static function ownerLinks(string $petId = '1'): Links
    {
        return new Links([
            'self' => 'http://example.com/pets/' . $petId . '/relationships/owner',
            'related' => 'http://example.com/pets/' . $petId . '/owner',
        ]);
    }

    /**
     * @param array<array-key, mixed>|null $meta
     */
    private static function notFound(?array $meta = null): ErrorObject
    {
        return new ErrorObject('404', 'not_found', 'not found', meta: $meta);
    }

    /**
     * one document per meta site, carrying `$meta` there and nowhere else
     *
     * @param array<array-key, mixed> $meta
     * @param array<string, string>   $expected site => the whole document as JSON
     *
     * @return iterable<string, array{AtomicResultsDocument|DataDocument|ErrorDocument, string}>
     */
    private static function sites(array $meta, array $expected): iterable
    {
        $documents = [
            'data document' => new DataDocument(null, new JsonApiObject(), self::selfLink(), null, $meta),
            'error document' => new ErrorDocument([self::notFound()], new JsonApiObject(), $meta),
            'atomic:results document' => new AtomicResultsDocument(
                [null],
                new JsonApiObject(ext: ['https://jsonapi.org/ext/atomic']),
                $meta,
            ),
            'resource object' => new DataDocument(
                new ResourceObject('pets', '1', self::selfLink(), [], [], $meta),
                new JsonApiObject(),
                self::selfLink(),
            ),
            'resource identifier object' => new DataDocument(
                new ResourceIdentifierObject('customers', '9', $meta),
                new JsonApiObject(),
                new Links(['self' => 'http://example.com/pets/1/relationships/owner']),
            ),
            'relationship object' => new DataDocument(
                new ResourceObject(
                    'pets',
                    '1',
                    self::selfLink(),
                    [],
                    ['owner' => RelationshipObject::toOne(self::ownerLinks(), null, $meta)],
                ),
                new JsonApiObject(),
                self::selfLink(),
            ),
            'link object' => new DataDocument(
                null,
                new JsonApiObject(),
                new Links(['self' => new Link('http://example.com/pets/1', title: 'the pet', meta: $meta)]),
            ),
            'error object' => new ErrorDocument([self::notFound($meta)], new JsonApiObject()),
        ];

        foreach ($documents as $site => $document) {
            yield $site => [$document, $expected[$site]];
        }
    }

    private static function customer(): ResourceObject
    {
        return new ResourceObject('customers', '9', new Links(['self' => 'http://example.com/customers/9']));
    }

    /**
     * built at runtime and typed `array<array-key, string>`,
     * since a name of digits becomes an int key;
     * passing it makes PHPStan hold the parameter types under test to that key type
     *
     * @param list<string> $names
     *
     * @return array<array-key, string>
     */
    private static function members(array $names): array
    {
        $members = [];

        foreach ($names as $name) {
            $members[$name] = $name;
        }

        return $members;
    }

    /**
     * @param list<string> $names
     *
     * @return array<array-key, RelationshipObject>
     */
    private static function relationships(array $names): array
    {
        $relationships = [];

        foreach ($names as $name) {
            $relationships[$name] = RelationshipObject::withoutLinkage(new Links([
                'self' => 'http://example.com/pets/1/relationships/' . $name,
                'related' => 'http://example.com/pets/1/' . $name,
            ]));
        }

        return $relationships;
    }
}
