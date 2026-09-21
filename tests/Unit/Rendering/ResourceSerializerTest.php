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

namespace Polunich\WpJsonApi\Tests\Unit\Rendering;

use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\SegmentCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Document\Link;
use Polunich\WpJsonApi\Document\Links;
use Polunich\WpJsonApi\Document\RelationshipObject;
use Polunich\WpJsonApi\Document\ResourceIdentifierObject;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\RecordLinkage;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\IdMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\RouteTable;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Rendering\PaginationWriter;
use Polunich\WpJsonApi\Rendering\ResourceSerializer;
use Polunich\WpJsonApi\Rendering\RouteUrls;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Routing\PlainUrls;
use ReflectionClass;
use UnexpectedValueException;

/**
 * records of every type are fixture `Pet`s,
 * and every readable `Samples` attribute reads `Pet::$name`, whatever the attribute is called
 *
 * @internal
 */
#[CoversClass(ResourceSerializer::class)]
final class ResourceSerializerTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(ResourceSerializer::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testAResourceObjectCarriesItsIdentityAndItsSelfLink(): void
    {
        $object = self::serializer()->resource(self::record('1'), self::graph(), self::fields(), []);

        self::assertSame('pets', $object->type);
        self::assertSame('1', $object->id);
        self::assertSame(['self' => '/pets/1'], self::hrefs($object->links));
        self::assertNull($object->meta);
    }

    public function testEveryLinkOfAResourceLiesBelowThePathOfItsType(): void
    {
        $registry = self::registry(self::pets(path: ['shop', 'pets']));

        $object = self::serializer($registry)->resource(self::record('1'), self::graph(), self::fields(), []);

        self::assertSame(['self' => '/shop/pets/1'], self::hrefs($object->links));
        self::assertSame(
            ['self' => '/shop/pets/1/relationships/orders', 'related' => '/shop/pets/1/orders'],
            self::hrefs($object->relationships['orders']->links),
        );
    }

    public function testTheAttributesStandInDeclarationOrderAndAreWrittenByTheirCodec(): void
    {
        $registry = self::registry(self::pets(name: self::marking()));

        $object = self::serializer($registry)->resource(self::record('1'), self::graph(), self::fields(), []);

        self::assertSame(['name' => 'wire:Rex'], $object->attributes);
    }

    #[DataProvider('fieldsetsThatNameTheSecret')]
    public function testAnAttributeWithoutAnAccessorIsNeverWritten(SparseFieldsets $fields): void
    {
        $object = self::serializer()->resource(self::record('1'), self::graph(), $fields, []);

        self::assertArrayNotHasKey('secret', $object->attributes);
    }

    /**
     * @return iterable<string, array{SparseFieldsets}>
     */
    public static function fieldsetsThatNameTheSecret(): iterable
    {
        yield 'no fieldset at all' => [self::fields()];
        yield 'a fieldset that names it' => [self::fields(['pets' => ['name', 'secret']])];
    }

    /**
     * @param list<string> $attributes
     * @param list<string> $relationships
     */
    #[DataProvider('fieldsets')]
    public function testTheFieldsetOfTheTypeDecidesWhichFieldsAreWritten(
        SparseFieldsets $fields,
        array $attributes,
        array $relationships,
    ): void {
        $object = self::serializer()->resource(self::record('1'), self::graph(), $fields, []);

        self::assertSame($attributes, array_keys($object->attributes));
        self::assertSame($relationships, array_keys($object->relationships));
    }

    /**
     * @return iterable<string, array{SparseFieldsets, list<string>, list<string>}>
     */
    public static function fieldsets(): iterable
    {
        yield 'no fieldset for the type' => [
            self::fields(['customers' => ['name']]),
            ['name'],
            ['owner', 'orders'],
        ];
        yield 'an attribute alone' => [self::fields(['pets' => ['name']]), ['name'], []];
        yield 'a relationship alone' => [self::fields(['pets' => ['orders']]), [], ['orders']];
        yield 'one of each' => [
            self::fields(['pets' => ['orders', 'name']]),
            ['name'],
            ['orders'],
        ];
        yield 'the empty fieldset, which admits no field' => [
            self::fields(['pets' => []]),
            [],
            [],
        ];
    }

    public function testARelationshipTheFieldsetLeavesOutIsDroppedWithItsLinkage(): void
    {
        $graph = self::graph(['pets' => ['1' => ['owner' => RecordLinkage::toOne(self::customer('7'))]]]);

        $object = self::serializer()->resource(
            self::record('1'),
            $graph,
            self::fields(['pets' => ['name']]),
            [],
        );

        self::assertSame([], $object->relationships);
    }

    /**
     * PHP turns a key of digits into an int, which the document model keeps as a member name
     */
    public function testAFieldNamedWithDigitsIsWrittenUnderItsIntKey(): void
    {
        $registry = self::registry(Samples::resource('pets', [Samples::attribute('123')]));

        $object = self::serializer($registry)->resource(self::record('1'), self::graph(), self::fields(), []);

        self::assertSame([123 => 'Rex'], $object->attributes);
    }

    public function testEveryRelationshipObjectCarriesItsSelfAndItsRelatedLink(): void
    {
        $object = self::serializer()->resource(self::record('1'), self::graph(), self::fields(), []);

        self::assertSame(
            ['self' => '/pets/1/relationships/owner', 'related' => '/pets/1/owner'],
            self::hrefs(self::relationship($object->relationships, 'owner')->links),
        );
        self::assertSame(
            ['self' => '/pets/1/relationships/orders', 'related' => '/pets/1/orders'],
            self::hrefs(self::relationship($object->relationships, 'orders')->links),
        );
    }

    public function testAResourceOfATypeThatExcludesTheFetchOfItsResourcesCarriesNoLink(): void
    {
        $registry = new ResourceRegistry([
            self::pets(),
            Samples::resource(
                'customers',
                [Samples::attribute('name')],
                capabilities: [Capability::ResourceLoader],
                excluded: RouteExclusion::except(
                    RouteOwner::Resource,
                    [OperationKind::FetchResource],
                    'type "customers"',
                ),
            ),
            Samples::resource('orders', [Samples::attribute('address')], capabilities: [Capability::CollectionReader]),
        ]);

        $object = self::serializer($registry)->resource(self::customer('7'), self::graph(), self::fields(), []);

        self::assertTrue($object->links->isEmpty());
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('keptRoutes')]
    public function testARelationshipLinksTheRoutesItKeeps(RouteExclusion $excluded, array $expected): void
    {
        $registry = self::registry(self::pets(owner: Samples::toOne('owner', 'customers', excluded: $excluded)));

        $object = self::serializer($registry)->resource(self::record('1'), self::graph(), self::fields(), []);

        self::assertSame($expected, self::hrefs(self::relationship($object->relationships, 'owner')->links));
    }

    /**
     * @return iterable<string, array{RouteExclusion, array<string, string>}>
     */
    public static function keptRoutes(): iterable
    {
        yield 'the relationship route alone' => [
            self::exceptRelationshipRead(OperationKind::FetchRelated),
            ['self' => '/pets/1/relationships/owner'],
        ];
        yield 'the related route alone' => [
            self::exceptRelationshipRead(OperationKind::FetchRelationship),
            ['related' => '/pets/1/owner'],
        ];
    }

    public function testARelationshipWithoutBothRoutesCarriesItsLinkageAlone(): void
    {
        $owner = Samples::toOne(
            'owner',
            'customers',
            excluded: self::exceptRelationshipRead(OperationKind::FetchRelated, OperationKind::FetchRelationship),
        );
        $graph = self::graph(['pets' => ['1' => ['owner' => RecordLinkage::toOne(self::customer('7'))]]]);

        $object = self::serializer(self::registry(self::pets(owner: $owner)))->resource(
            self::record('1'),
            $graph,
            self::fields(),
            [],
        );
        $relationship = self::relationship($object->relationships, 'owner');

        self::assertTrue($relationship->links->isEmpty());
        self::assertEquals(new ResourceIdentifierObject('customers', '7'), $relationship->linkage());
    }

    public function testAToManyLinkageWithoutItsRelationshipRouteIsRefused(): void
    {
        $orders = Samples::toMany(
            'orders',
            'orders',
            includable: false,
            excluded: self::exceptRelationshipRead(OperationKind::FetchRelationship),
        );
        $page = new Page([], Strategy::PageNumber, 2, 1, null, false, false, false, null, null, null, null);
        $graph = self::graph(['pets' => ['1' => ['orders' => RecordLinkage::toMany($page)]]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the to-many relationship "orders" of type "pets" carries its linkage and'
            . ' excludes its relationship route');

        self::serializer(self::registry(self::pets(orders: $orders)))->resource(
            self::record('1'),
            $graph,
            self::fields(),
            [],
        );
    }

    public function testARelationshipTheGraphDoesNotNameCarriesNoDataMember(): void
    {
        $object = self::serializer()->resource(self::record('1'), self::graph(), self::fields(), []);

        self::assertFalse(self::relationship($object->relationships, 'owner')->hasLinkage());
        self::assertNull(self::relationship($object->relationships, 'owner')->linkage());
    }

    #[DataProvider('toOneLinkages')]
    public function testAToOneLinkageIsTheIdentifierOfTheRelatedResourceOrNull(
        ?ResourceRecord $related,
        ?ResourceIdentifierObject $expected,
    ): void {
        $graph = self::graph(['pets' => ['1' => ['owner' => RecordLinkage::toOne($related)]]]);

        $object = self::serializer()->resource(self::record('1'), $graph, self::fields(), []);
        $owner = self::relationship($object->relationships, 'owner');

        self::assertTrue($owner->hasLinkage());
        self::assertEquals($expected, $owner->linkage());
    }

    /**
     * @return iterable<string, array{?ResourceRecord, ?ResourceIdentifierObject}>
     */
    public static function toOneLinkages(): iterable
    {
        yield 'a related resource' => [self::customer('7'), new ResourceIdentifierObject('customers', '7')];
        yield 'the empty to-one relationship' => [null, null];
    }

    public function testAToManyLinkageIsThePageOfIdentifiersWithThePaginationLinksOfItsRoute(): void
    {
        $page = new Page(
            [self::order('5'), self::order('6')],
            Strategy::PageNumber,
            2,
            1,
            null,
            true,
            false,
            false,
            null,
            null,
            null,
            null,
        );
        $graph = self::graph(['pets' => ['1' => ['orders' => RecordLinkage::toMany($page)]]]);

        $orders = self::relationship(
            self::serializer()->resource(self::record('1'), $graph, self::fields(), [])->relationships,
            'orders',
        );

        self::assertEquals(
            [new ResourceIdentifierObject('orders', '5'), new ResourceIdentifierObject('orders', '6')],
            $orders->linkage(),
        );
        self::assertSame(
            [
                'self' => '/pets/1/relationships/orders',
                'related' => '/pets/1/orders',
                'first' => '/pets/1/relationships/orders?{"page":{"number":1,"size":2}}',
                'prev' => null,
                'next' => '/pets/1/relationships/orders?{"page":{"number":2,"size":2}}',
            ],
            self::hrefs($orders->links),
        );
    }

    public function testEveryLinkOfTheResourceCarriesTheLanguageParameterAndNoOtherPartOfTheQuery(): void
    {
        $page = new Page(
            [self::order('5')],
            Strategy::PageNumber,
            1,
            1,
            null,
            true,
            false,
            false,
            null,
            null,
            null,
            null,
        );
        $graph = self::graph(['pets' => ['1' => ['orders' => RecordLinkage::toMany($page)]]]);

        $object = self::serializer()->resource(self::record('1'), $graph, self::fields(), ['uiLocale' => 'uk']);

        self::assertSame(['self' => '/pets/1?{"uiLocale":"uk"}'], self::hrefs($object->links));
        self::assertSame(
            [
                'self' => '/pets/1/relationships/owner?{"uiLocale":"uk"}',
                'related' => '/pets/1/owner?{"uiLocale":"uk"}',
            ],
            self::hrefs(self::relationship($object->relationships, 'owner')->links),
        );
        self::assertSame(
            [
                'self' => '/pets/1/relationships/orders?{"uiLocale":"uk"}',
                'related' => '/pets/1/orders?{"uiLocale":"uk"}',
                'first' => '/pets/1/relationships/orders?{"uiLocale":"uk","page":{"number":1,"size":1}}',
                'prev' => null,
                'next' => '/pets/1/relationships/orders?{"uiLocale":"uk","page":{"number":2,"size":1}}',
            ],
            self::hrefs(self::relationship($object->relationships, 'orders')->links),
        );
    }

    public function testAToManyRelationshipObjectCarriesNoMeta(): void
    {
        $page = new Page([], Strategy::PageNumber, 2, 1, null, false, false, false, null, null, null, null);
        $graph = self::graph(['pets' => ['1' => ['orders' => RecordLinkage::toMany($page)]]]);

        $object = self::serializer()->resource(self::record('1'), $graph, self::fields(), []);

        self::assertSame([], self::relationship($object->relationships, 'orders')->linkage());
        self::assertNull(self::relationship($object->relationships, 'orders')->meta);
    }

    public function testAnIdentifierIsTheTypeAndTheIdWithNoMeta(): void
    {
        $identifier = self::serializer()->identifier(self::record('1'));

        self::assertSame('pets', $identifier->type);
        self::assertSame('1', $identifier->id);
        self::assertNull($identifier->meta);
    }

    /**
     * `UuidCodec` writes lower case, so an upper-case id of the accessor shows where the codec ran
     */
    public function testTheIdCodecWritesEveryIdOfADocumentAndOfAUrl(): void
    {
        $registry = self::registry(self::pets(id: self::uuids()));
        $record = self::record('3F2504E0-4F89-11D3-9A0C-0305E82C3301');
        $serializer = self::serializer($registry);

        $object = $serializer->resource($record, self::graph(), self::fields(), []);

        self::assertSame('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $serializer->documentId($record));
        self::assertSame('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $serializer->identifier($record)->id);
        self::assertSame('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $object->id);
        self::assertSame(
            ['self' => '/pets/3f2504e0-4f89-11d3-9a0c-0305e82c3301'],
            self::hrefs($object->links),
        );
    }

    public function testTheGraphIsReadWithTheIdOfTheRecordAndTheLinksCarryTheIdOfTheDocument(): void
    {
        $registry = self::registry(self::pets(id: self::uuids()));
        $raw = '3F2504E0-4F89-11D3-9A0C-0305E82C3301';
        $graph = self::graph(['pets' => [$raw => ['owner' => RecordLinkage::toOne(self::customer('7'))]]]);

        $object = self::serializer($registry)->resource(self::record($raw), $graph, self::fields(), []);
        $owner = self::relationship($object->relationships, 'owner');

        self::assertTrue($owner->hasLinkage());
        self::assertSame(
            [
                'self' => '/pets/3f2504e0-4f89-11d3-9a0c-0305e82c3301/relationships/owner',
                'related' => '/pets/3f2504e0-4f89-11d3-9a0c-0305e82c3301/owner',
            ],
            self::hrefs($owner->links),
        );
    }

    #[DataProvider('idCodecsThatWriteNoId')]
    public function testRefusesAnIdTheCodecDoesNotWriteAsANonEmptyString(SegmentCodec $codec, string $message): void
    {
        $registry = self::registry(self::pets(id: new IdMetadata(
            $codec,
            static function (Pet $pet): string {
                return $pet->id;
            },
            false,
        )));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::serializer($registry)->documentId(self::record('1'));
    }

    /**
     * @return iterable<string, array{SegmentCodec, string}>
     */
    public static function idCodecsThatWriteNoId(): iterable
    {
        yield 'a codec that writes no string' => [
            self::writing(null),
            'the id codec of type "pets" wrote the id "1" as null',
        ];
        yield 'a codec that writes the empty string' => [
            self::writing(''),
            'the id codec of type "pets" wrote the id "1" as the empty string',
        ];
    }

    public function testRefusesAnIdTheCodecOfTheTypeRejects(): void
    {
        $registry = self::registry(self::pets(id: self::uuids()));

        try {
            self::serializer($registry)->documentId(self::record('not a uuid'));
            self::fail('the serializer wrote an id the codec of the type refuses');
        } catch (UnexpectedValueException $exception) {
            self::assertStringContainsString(
                'the id codec of type "pets" refuses the id "not a uuid"',
                $exception->getMessage(),
            );
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        }
    }

    public function testRefusesAnAttributeValueTheCodecOfTheAttributeRejects(): void
    {
        $registry = self::registry(self::pets(name: new IntegerCodec()));

        try {
            self::serializer($registry)->resource(self::record('1'), self::graph(), self::fields(), []);
            self::fail('the serializer wrote an attribute value the codec of the attribute refuses');
        } catch (UnexpectedValueException $exception) {
            self::assertStringContainsString(
                'the codec of attribute "name" of type "pets" refuses what the accessor of the'
                . ' declaration returned for the record of id "1"',
                $exception->getMessage(),
            );
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        }
    }

    public function testRefusesAnAttributeValueOutsideTheBoundsOfItsCodec(): void
    {
        $registry = self::registry(self::pets(name: new StringCodec(1, 2)));

        try {
            self::serializer($registry)->resource(self::record('1'), self::graph(), self::fields(), []);
            self::fail('the serializer wrote an attribute value outside the bounds of its codec');
        } catch (UnexpectedValueException $exception) {
            self::assertStringContainsString('value_too_long at ""', $exception->getMessage());
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        }
    }

    #[DataProvider('typesWithoutAReadableField')]
    public function testRefusesAResourceObjectOfARecordTheLibraryDoesNotHold(ResourceMetadata $pets): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the library holds no record of type "pets" and id "1"');

        self::serializer(self::registry($pets))->resource(
            new ResourceRecord('pets', '1', null),
            self::graph(),
            self::fields(),
            [],
        );
    }

    /**
     * @return iterable<string, array{ResourceMetadata}>
     */
    public static function typesWithoutAReadableField(): iterable
    {
        yield 'a type with a readable attribute' => [self::pets()];
        yield 'a type whose every attribute is written and never read' => [
            Samples::resource('pets', [Samples::attribute('secret', readable: false)]),
        ];
    }

    #[DataProvider('callsThatNameAType')]
    public function testRefusesATypeThatIsNotDeclared(string $method): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('type "unicorns" is not declared');

        $record = new ResourceRecord('unicorns', '1', new Pet('1', 'Rex'));
        $serializer = self::serializer();

        match ($method) {
            'resource' => $serializer->resource($record, self::graph(), self::fields(), []),
            'identifier' => $serializer->identifier($record),
            default => $serializer->documentId($record),
        };
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function callsThatNameAType(): iterable
    {
        yield 'resource' => ['resource'];
        yield 'identifier' => ['identifier'];
        yield 'documentId' => ['documentId'];
    }

    /**
     * @param array<array-key, RelationshipObject> $relationships
     */
    private static function relationship(array $relationships, string $name): RelationshipObject
    {
        self::assertArrayHasKey($name, $relationships);

        return $relationships[$name];
    }

    /**
     * @return array<array-key, string|null>
     */
    private static function hrefs(Links $links): array
    {
        return array_map(static function (?Link $link): ?string {
            return $link?->href;
        }, $links->all());
    }

    private static function serializer(?ResourceRegistry $registry = null): ResourceSerializer
    {
        $registry ??= self::registry();
        $routes = RouteTable::of($registry, [], []);
        $urls = new RouteUrls($routes, PlainUrls::jsonQuery());

        return new ResourceSerializer($registry, $routes, $urls, new PaginationWriter($urls, NamingPolicy::SnakeCase));
    }

    private static function registry(?ResourceMetadata $pets = null): ResourceRegistry
    {
        return new ResourceRegistry([
            $pets ?? self::pets(),
            Samples::resource('customers', [Samples::attribute('name')]),
            Samples::resource('orders', [Samples::attribute('address')], capabilities: [Capability::CollectionReader]),
        ]);
    }

    /**
     * the registry requires the related type of the to-many `orders` to bind a collection reader,
     * which `registry()` does
     *
     * @param ?Codec<mixed>           $name a `StringCodec` when null
     * @param ?non-empty-list<string> $path
     */
    private static function pets(
        ?IdMetadata $id = null,
        ?Codec $name = null,
        ?RelationshipMetadata $owner = null,
        ?RelationshipMetadata $orders = null,
        ?array $path = null,
    ): ResourceMetadata {
        return Samples::resource(
            'pets',
            [Samples::attribute('name', $name), Samples::attribute('secret', readable: false)],
            [$owner ?? Samples::toOne('owner', 'customers'), $orders ?? Samples::toMany('orders', 'orders')],
            capabilities: [Capability::RelatedLoader],
            id: $id,
            path: $path,
        );
    }

    private static function exceptRelationshipRead(OperationKind $kind, OperationKind ...$kinds): RouteExclusion
    {
        return RouteExclusion::except(RouteOwner::Relationship, [$kind, ...array_values($kinds)], 'a relationship');
    }

    private static function uuids(): IdMetadata
    {
        return new IdMetadata(new UuidCodec(), static function (Pet $pet): string {
            return $pet->id;
        }, false);
    }

    /**
     * @param array<array-key, array<array-key, array<array-key, RecordLinkage>>> $linkage
     */
    private static function graph(array $linkage = []): RecordGraph
    {
        return new RecordGraph(false, [], $linkage);
    }

    /**
     * @param array<array-key, list<string>> $fieldsets
     */
    private static function fields(array $fieldsets = []): SparseFieldsets
    {
        return new SparseFieldsets($fieldsets);
    }

    private static function record(string $id): ResourceRecord
    {
        return new ResourceRecord('pets', $id, new Pet($id, 'Rex'));
    }

    private static function customer(string $id): ResourceRecord
    {
        return new ResourceRecord('customers', $id, new Pet($id, 'Ada Lovelace'));
    }

    private static function order(string $id): ResourceRecord
    {
        return new ResourceRecord('orders', $id, new Pet($id, '1 Main Street'));
    }

    /**
     * prefixes what it writes with `wire:`, so a test tells an encoded value from a raw one
     *
     * @return Codec<string>
     */
    private static function marking(): Codec
    {
        return new class implements Codec {
            private readonly StringCodec $strings;

            public function __construct()
            {
                $this->strings = new StringCodec();
            }

            #[Override]
            public function decode(mixed $input, Pointer $pointer): string
            {
                return $this->strings->decode($input, $pointer);
            }

            #[Override]
            public function encode(mixed $value): mixed
            {
                return 'wire:' . $this->strings->encode($value);
            }

            #[Override]
            public function schema(): Schema
            {
                return $this->strings->schema();
            }
        };
    }

    private static function writing(mixed $written): SegmentCodec
    {
        return new class ($written) implements SegmentCodec {
            private readonly StringCodec $strings;

            public function __construct(private readonly mixed $written)
            {
                $this->strings = new StringCodec();
            }

            #[Override]
            public function decode(mixed $input, Pointer $pointer): string
            {
                return $this->strings->decode($input, $pointer);
            }

            #[Override]
            public function encode(mixed $value): mixed
            {
                return $this->written;
            }

            #[Override]
            public function schema(): Schema
            {
                return $this->strings->schema();
            }

            #[Override]
            public function segmentPattern(): string
            {
                return '[^/]+';
            }
        };
    }
}
