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
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\ParentScope;
use Polunich\WpJsonApi\Query\Sort\Direction;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Sort\SortField;
use Polunich\WpJsonApi\Query\Window;
use Polunich\WpJsonApi\Store\CollectionQuery;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(CollectionQuery::class)]
final class CollectionQueryTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(CollectionQuery::class))->isReadOnly());
    }

    public function testCarriesTheWholeReadingOfOneCollection(): void
    {
        $filter = new Condition(new FieldPath(['city']), '@eq', 'Kyiv');
        $sort = new Sort([
            new SortField(new FieldPath(['created']), Direction::Descending),
            new SortField(new FieldPath(['id'])),
        ]);
        $window = new Window(0, 21);
        $fields = new FieldHint(['name'], ['created']);
        $languages = new LanguagePreferences([new LanguageRange('en')]);
        $parent = new ParentScope('pets', '42', 'orders');

        $query = new CollectionQuery(
            type: 'orders',
            filter: $filter,
            sort: $sort,
            window: $window,
            totalRequested: true,
            fields: $fields,
            languages: $languages,
            parent: $parent,
            queryParameters: new Values([]),
        );

        self::assertSame('orders', $query->type);
        self::assertSame($filter, $query->filter);
        self::assertSame($sort, $query->sort);
        self::assertSame($window, $query->window);
        self::assertTrue($query->totalRequested);
        self::assertSame($fields, $query->fields);
        self::assertSame($languages, $query->languages);
        self::assertSame($parent, $query->parent);
    }

    public function testTheCollectionRouteFiltersNothingAndHangsFromNoResource(): void
    {
        $query = self::query('pets');

        self::assertNull($query->filter);
        self::assertNull($query->parent);
    }

    /**
     * parameter names are public API: a project names them when it builds a query in its own tests
     */
    public function testTheConstructorDeclaresItsPublicParameters(): void
    {
        $parameters = array_map(
            static function (ReflectionParameter $parameter): array {
                return [
                    $parameter->getName(),
                    (string) $parameter->getType(),
                ];
            },
            (new ReflectionMethod(CollectionQuery::class, '__construct'))->getParameters(),
        );

        self::assertSame([
            ['type', 'string'],
            ['filter', '?' . FilterNode::class],
            ['sort', Sort::class],
            ['window', Window::class],
            ['totalRequested', 'bool'],
            ['fields', FieldHint::class],
            ['languages', LanguagePreferences::class],
            ['parent', '?' . ParentScope::class],
            ['queryParameters', Values::class],
        ], $parameters);
    }

    #[DataProvider('illegalTypes')]
    public function testRefusesATypeThatIsNoMemberName(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no resource type carries it');

        self::query($type);
    }

    #[DataProvider('sortsWithoutId')]
    public function testRefusesASortThatDoesNotHoldId(Sort $sort): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sort "' . $sort->toString() . '" does not hold id');

        self::query('pets', $sort);
    }

    /**
     * @return iterable<string, array{Sort}>
     */
    public static function sortsWithoutId(): iterable
    {
        yield 'the empty sort' => [new Sort([])];
        yield 'one field' => [new Sort([new SortField(new FieldPath(['created']), Direction::Descending)])];
        yield 'the id of a related resource' => [new Sort([new SortField(new FieldPath(['owner', 'id']))])];
        yield 'a field that differs from id in case' => [new Sort([new SortField(new FieldPath(['ID']))])];
    }

    #[DataProvider('sortsWithId')]
    public function testTakesEverySortThatHoldsId(Sort $sort): void
    {
        self::assertSame($sort, self::query('pets', $sort)->sort);
    }

    /**
     * @return iterable<string, array{Sort}>
     */
    public static function sortsWithId(): iterable
    {
        yield 'id alone' => [new Sort([new SortField(new FieldPath(['id']))])];
        yield 'id descending' => [new Sort([new SortField(new FieldPath(['id']), Direction::Descending)])];
        yield 'id last' => [new Sort([
            new SortField(new FieldPath(['created']), Direction::Descending),
            new SortField(new FieldPath(['id'])),
        ])];
        yield 'id first' => [new Sort([
            new SortField(new FieldPath(['id'])),
            new SortField(new FieldPath(['created'])),
        ])];
    }

    public function testChecksTheTypeBeforeTheSort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no resource type carries it');

        self::query('', new Sort([]));
    }

    public function testEscapesAControlCharacterOfARefusedTypeInTheMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pe\nts" is no member name, so no resource type carries it');

        self::query("pe\nts");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function illegalTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'a reserved character' => ['pe.ts'];
        yield 'a bracket of a parameter family' => ['pets[]'];
        yield 'a hyphen last' => ['pets-'];
    }

    private static function query(string $type, ?Sort $sort = null): CollectionQuery
    {
        return new CollectionQuery(
            type: $type,
            filter: null,
            sort: $sort ?? new Sort([new SortField(new FieldPath(['id']))]),
            window: new Window(0, 21),
            totalRequested: false,
            fields: new FieldHint(null),
            languages: new LanguagePreferences([]),
            parent: null,
            queryParameters: new Values([]),
        );
    }
}
