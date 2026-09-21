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
use Polunich\WpJsonApi\Operation\FetchCollection;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Sort\Direction;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Sort\SortField;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(FetchCollection::class)]
final class FetchCollectionTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(FetchCollection::class))->isReadOnly());
    }

    public function testCarriesWhatTheClientAskedFor(): void
    {
        $filter = new Condition(new FieldPath(['city']), '@eq', 'Kyiv');
        $sort = new Sort([new SortField(new FieldPath(['listedAt']), Direction::Descending)]);
        $page = PageRequest::pageNumber(2, 10);
        $include = new IncludePaths([new IncludePath(['store'])]);
        $fields = new SparseFieldsets(['pets' => ['name']]);
        $languages = new LanguagePreferences([new LanguageRange('uk')]);

        $operation = new FetchCollection(
            type: 'pets',
            filter: $filter,
            sort: $sort,
            page: $page,
            include: $include,
            fields: $fields,
            languages: $languages,
            queryParameters: new Values([]),
        );

        self::assertSame('pets', $operation->type);
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
                ['type', 'string'],
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
                (new ReflectionMethod(FetchCollection::class, '__construct'))->getParameters(),
            ),
        );
    }

    public function testIsTheFetchCollectionOperationOfItsType(): void
    {
        $operation = self::operation(null);

        self::assertSame(OperationKind::FetchCollection, $operation->kind());
        self::assertSame('pets', $operation->type());
        self::assertNull($operation->relationship());
    }

    public function testAnAbsentIncludeParameterIsNoEmptyOne(): void
    {
        $empty = new IncludePaths([]);

        self::assertNull(self::operation(null)->include);
        self::assertSame($empty, self::operation($empty)->include);
    }

    public function testRefusesAnEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a type is never empty');

        new FetchCollection(
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

    private static function operation(?IncludePaths $include): FetchCollection
    {
        return new FetchCollection(
            type: 'pets',
            filter: null,
            sort: new Sort([]),
            page: PageRequest::pageNumber(),
            include: $include,
            fields: new SparseFieldsets([]),
            languages: new LanguagePreferences([]),
            queryParameters: new Values([]),
        );
    }
}
