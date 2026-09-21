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

namespace Polunich\WpJsonApi\Tests\Unit\Store;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\Sort\Direction;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Sort\SortField;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PortSignatureReader;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;

/**
 * @internal
 */
#[CoversClass(RelatedLoadRequest::class)]
final class RelatedLoadRequestTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(RelatedLoadRequest::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    public function testTheClassIsPublicApi(): void
    {
        self::assertStringNotContainsString(
            '@internal',
            (string) (new ReflectionClass(RelatedLoadRequest::class))->getDocComment(),
        );
    }

    /**
     * the parameter names are public API, and so are the properties a loader reads
     */
    public function testTheConstructorPromotesItsPublicParameters(): void
    {
        $class = new ReflectionClass(RelatedLoadRequest::class);
        $expected = [
            ['type', 'string'],
            ['parentIds', 'array'],
            ['relationship', 'string'],
            ['sort', Sort::class],
            ['limit', 'int'],
            ['fields', FieldHint::class],
            ['languages', LanguagePreferences::class],
            ['queryParameters', Values::class],
        ];

        self::assertSame(
            $expected,
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getConstructor()?->getParameters() ?? [],
            ),
        );

        $properties = [];

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $properties[$property->getName()] = (string) $property->getType();
        }

        ksort($properties);
        $expectedProperties = array_column($expected, 1, 0);
        ksort($expectedProperties);

        self::assertSame($expectedProperties, $properties);
    }

    public function testTheParentIdsAreANonEmptyList(): void
    {
        self::assertSame(
            ['non-empty-list<string>'],
            PortSignatureReader::tags(new ReflectionProperty(RelatedLoadRequest::class, 'parentIds'), 'var'),
        );
    }

    public function testTheReadingOfAToOneRelationshipReadsOneRecordInTheSortById(): void
    {
        $fields = new FieldHint(['name']);
        $languages = new LanguagePreferences([new LanguageRange('en', 1.0)]);
        $request = RelatedLoadRequest::toOne('pets', ['1', '2'], 'owner', $fields, $languages, new Values([]));

        self::assertSame('pets', $request->type);
        self::assertSame(['1', '2'], $request->parentIds);
        self::assertSame('owner', $request->relationship);
        self::assertEquals(Sort::byId(), $request->sort);
        self::assertSame(1, $request->limit);
        self::assertSame($fields, $request->fields);
        self::assertSame($languages, $request->languages);
        self::assertTrue($request->readsOne());
        self::assertFalse(
            (new RelatedLoadRequest(
                'pets',
                ['1'],
                'orders',
                Sort::byId(),
                11,
                $fields,
                $languages,
                new Values([]),
            ))->readsOne(),
        );
    }

    public function testCarriesEveryMember(): void
    {
        $sort = new Sort([
            new SortField(new FieldPath(['placedAt']), Direction::Descending),
            new SortField(new FieldPath(['id'])),
        ]);
        $fields = new FieldHint(['address'], ['placedAt']);
        $languages = new LanguagePreferences([new LanguageRange('uk')]);

        $request = new RelatedLoadRequest(
            type: 'pets',
            parentIds: ['7', '3'],
            relationship: 'orders',
            sort: $sort,
            limit: 11,
            fields: $fields,
            languages: $languages,
            queryParameters: new Values([]),
        );

        self::assertSame('pets', $request->type);
        self::assertSame(['7', '3'], $request->parentIds);
        self::assertSame('orders', $request->relationship);
        self::assertSame($sort, $request->sort);
        self::assertSame(11, $request->limit);
        self::assertSame($fields, $request->fields);
        self::assertSame($languages, $request->languages);
    }

    /**
     * only the structure is checked: a name that is no member name passes
     *
     * @param list<string> $parentIds
     */
    #[DataProvider('requests')]
    public function testTakesEveryRequestOfItsStructure(
        string $type,
        array $parentIds,
        string $relationship,
        Sort $sort,
        int $limit,
    ): void {
        $request = new RelatedLoadRequest(
            $type,
            $parentIds,
            $relationship,
            $sort,
            $limit,
            new FieldHint(null),
            new LanguagePreferences([]),
            new Values([]),
        );

        self::assertSame($parentIds, $request->parentIds);
        self::assertSame($sort, $request->sort);
        self::assertSame($limit, $request->limit);
    }

    /**
     * @return iterable<string, array{string, list<string>, string, Sort, int}>
     */
    public static function requests(): iterable
    {
        $id = new Sort([new SortField(new FieldPath(['id']))]);

        yield 'a to-one relationship' => ['pets', ['1'], 'owner', $id, 1];
        yield 'a to-many relationship' => ['pets', ['1', '2'], 'orders', new Sort([
            new SortField(new FieldPath(['placedAt']), Direction::Descending),
            new SortField(new FieldPath(['id'])),
        ]), 11];
        yield 'id descending' => [
            'pets',
            ['1'],
            'orders',
            new Sort([new SortField(new FieldPath(['id']), Direction::Descending)]),
            2,
        ];
        yield 'numeric strings that differ as strings' => ['pets', ['1', '01', '1.0', ' 1', '-0'], 'orders', $id, 11];
        yield 'names that are no member names' => ['pe.ts', ['0'], 'or.ders', $id, 1];
    }

    public function testRefusesAnEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('related records are loaded for parents of a resource type, and a type is'
            . ' never empty');

        self::request(type: '');
    }

    public function testRefusesARequestWithoutParents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('related records are loaded for one or more parents of type "pets", and this'
            . ' request names none');

        self::request(parentIds: []);
    }

    /**
     * @param list<string> $parentIds
     */
    #[DataProvider('parentListsWithAnEmptyId')]
    public function testRefusesAnEmptyParentId(array $parentIds): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('names a parent of type "pets" with an empty id, which identifies no resource');

        self::request(parentIds: $parentIds);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function parentListsWithAnEmptyId(): iterable
    {
        yield 'the only parent' => [['']];
        yield 'after another parent' => [['1', '']];
    }

    /**
     * @param list<string> $parentIds
     */
    #[DataProvider('parentListsWithARepeatedId')]
    public function testRefusesAParentNamedTwice(array $parentIds, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::request(parentIds: $parentIds);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function parentListsWithARepeatedId(): iterable
    {
        yield 'side by side' => [
            ['1', '1'],
            'names parent "1" of type "pets" twice, and the records of each parent are returned once',
        ];
        yield 'apart' => [['7', '3', '7'], 'names parent "7" of type "pets" twice'];
        yield 'an id that is no decimal integer' => [['a', 'b', 'a'], 'names parent "a" of type "pets" twice'];
    }

    public function testRefusesAnEmptyRelationship(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('related records of parents of type "pets" are loaded through a relationship,'
            . ' whose name is never empty');

        self::request(relationship: '');
    }

    /**
     * `customer.id` is a field of a related resource, not the id the sort must hold
     */
    #[DataProvider('sortsWithoutId')]
    public function testRefusesASortWithoutId(Sort $sort): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sort "' . $sort->toString() . '" of relationship "orders" does not hold id');

        self::request(sort: $sort);
    }

    /**
     * @return iterable<string, array{Sort}>
     */
    public static function sortsWithoutId(): iterable
    {
        yield 'the empty sort' => [new Sort([])];
        yield 'another field' => [new Sort([new SortField(new FieldPath(['placedAt']))])];
        yield 'the id of a related resource' => [new Sort([new SortField(new FieldPath(['customer', 'id']))])];
    }

    #[DataProvider('limitsBelowOne')]
    public function testRefusesALimitBelowOne(int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'related records of relationship "orders" are loaded %d at most for each parent, and a load reads at'
            . ' least one',
            $limit,
        ));

        self::request(limit: $limit);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function limitsBelowOne(): iterable
    {
        yield 'no record' => [0];
        yield 'fewer than none' => [-1];
        yield 'the smallest int' => [PHP_INT_MIN];
    }

    /**
     * @param list<string> $parentIds
     */
    #[DataProvider('refusalsNamingOutsideValues')]
    public function testEscapesAControlCharacterOfEveryValueItNamesInTheMessage(
        string $type,
        array $parentIds,
        string $relationship,
        ?Sort $sort,
        int $limit,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::request($type, $parentIds, $relationship, $sort, $limit);
    }

    /**
     * @return iterable<string, array{string, list<string>, string, ?Sort, int, string}>
     */
    public static function refusalsNamingOutsideValues(): iterable
    {
        yield 'the type of a request without parents' => [
            "pe\tts",
            [],
            'orders',
            null,
            11,
            'related records are loaded for one or more parents of type "pe\tts", and this request names none',
        ];
        yield 'the type of a request with an empty parent id' => [
            "pe\tts",
            ['1', ''],
            'orders',
            null,
            11,
            'names a parent of type "pe\tts" with an empty id, which identifies no resource',
        ];
        yield 'the parent named twice and its type' => [
            "pe\tts",
            ["1\n2", "1\n2"],
            'orders',
            null,
            11,
            'names parent "1\n2" of type "pe\tts" twice',
        ];
        yield 'the type of a request with an empty relationship' => [
            "pe\tts",
            ['1'],
            '',
            null,
            11,
            'related records of parents of type "pe\tts" are loaded through a relationship, whose name is never empty',
        ];
        yield 'the relationship of a sort without id' => [
            'pets',
            ['1'],
            "or\nders",
            new Sort([new SortField(new FieldPath(['placedAt']))]),
            11,
            'sort "placedAt" of relationship "or\nders" does not hold id',
        ];
        yield 'the relationship of a limit below one' => [
            'pets',
            ['1'],
            "or\nders",
            null,
            0,
            'related records of relationship "or\nders" are loaded 0 at most for each parent',
        ];
    }

    /**
     * @param list<string> $parentIds
     */
    private static function request(
        string $type = 'pets',
        array $parentIds = ['1'],
        string $relationship = 'orders',
        ?Sort $sort = null,
        int $limit = 11,
    ): RelatedLoadRequest {
        return new RelatedLoadRequest(
            $type,
            $parentIds,
            $relationship,
            $sort ?? new Sort([new SortField(new FieldPath(['id']))]),
            $limit,
            new FieldHint(null),
            new LanguagePreferences([]),
            new Values([]),
        );
    }
}
