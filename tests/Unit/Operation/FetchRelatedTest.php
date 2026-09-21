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

namespace Polunich\WpJsonApi\Tests\Unit\Operation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Operation\FetchRelated;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Sort\SortField;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(FetchRelated::class)]
final class FetchRelatedTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(FetchRelated::class))->isReadOnly());
    }

    public function testCarriesTheParentTheRelationshipAndTheCollectionFeatures(): void
    {
        $parent = new Identifier('pets', '42');
        $filter = new Condition(new FieldPath(['rating']), '@gte', 4);
        $sort = new Sort([new SortField(new FieldPath(['placedAt']))]);
        $page = PageRequest::offset(20, 10);
        $include = new IncludePaths([new IncludePath(['customer'])]);
        $fields = new SparseFieldsets(['orders' => ['address']]);
        $languages = new LanguagePreferences([new LanguageRange('uk')]);

        $operation = new FetchRelated(
            parent: $parent,
            relationship: 'orders',
            filter: $filter,
            sort: $sort,
            page: $page,
            include: $include,
            fields: $fields,
            languages: $languages,
            queryParameters: new Values([]),
        );

        self::assertSame($parent, $operation->parent);
        self::assertSame('orders', $operation->relationship);
        self::assertSame($filter, $operation->filter);
        self::assertSame($sort, $operation->sort);
        self::assertSame($page, $operation->page);
        self::assertSame($include, $operation->include);
        self::assertSame($fields, $operation->fields);
        self::assertSame($languages, $operation->languages);
    }

    public function testTheConstructorDeclaresItsPublicParameters(): void
    {
        self::assertSame(
            [
                ['parent', Identifier::class],
                ['relationship', 'string'],
                ['filter', '?' . FilterNode::class],
                ['sort', Sort::class],
                ['page', PageRequest::class],
                ['include', '?' . IncludePaths::class],
                ['fields', SparseFieldsets::class],
                ['languages', LanguagePreferences::class],
                ['queryParameters', Values::class],
            ],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                (new ReflectionMethod(FetchRelated::class, '__construct'))->getParameters(),
            ),
        );
    }

    public function testIsTheFetchRelatedOperationOfTheTypeOfItsParent(): void
    {
        $operation = self::operation(new Identifier('pets', '42'));

        self::assertSame(OperationKind::FetchRelated, $operation->kind());
        self::assertSame('pets', $operation->type);
        self::assertSame('pets', $operation->type());
        self::assertSame('orders', $operation->relationship());
    }

    public function testRefusesAnEmptyRelationshipName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the related resources of a parent of type "pets" are read through a'
            . ' relationship, whose name is never empty');

        new FetchRelated(
            new Identifier('pets', '42'),
            '',
            null,
            new Sort([]),
            PageRequest::pageNumber(),
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    public function testEscapesAControlCharacterOfTheTypeOfTheParentInTheMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the related resources of a parent of type "pe\nts" are read through a'
            . ' relationship, whose name is never empty');

        new FetchRelated(
            new Identifier("pe\nts", '42'),
            '',
            null,
            new Sort([]),
            PageRequest::pageNumber(),
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    private static function operation(Identifier $parent): FetchRelated
    {
        return new FetchRelated(
            parent: $parent,
            relationship: 'orders',
            filter: new Condition(new FieldPath(['rating']), '@gte', 4),
            sort: new Sort([new SortField(new FieldPath(['placedAt']))]),
            page: PageRequest::pageNumber(1, 10),
            include: null,
            fields: new SparseFieldsets([]),
            languages: new LanguagePreferences([]),
            queryParameters: new Values([]),
        );
    }
}
