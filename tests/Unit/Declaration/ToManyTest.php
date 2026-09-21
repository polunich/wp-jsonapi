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

namespace Polunich\WpJsonApi\Tests\Unit\Declaration;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Declaration\ToMany;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use ReflectionClass;

/**
 * what a to-many relationship adds to `Relationship`, whose own parts `RelationshipTest` covers
 *
 * @internal
 */
#[CoversClass(ToMany::class)]
final class ToManyTest extends TestCase
{
    public function testTheClassIsFinalMutableAndPublicApi(): void
    {
        $class = new ReflectionClass(ToMany::class);

        self::assertTrue($class->isFinal());
        self::assertFalse($class->isReadOnly());
        self::assertStringNotContainsString('@internal', (string) $class->getDocComment());
        self::assertStringContainsString('@internal', (string) $class->getMethod('build')->getDocComment());
    }

    public function testEveryMethodReturnsTheBuilderItChanged(): void
    {
        $relationship = ToMany::make('tags', 'tags');

        self::assertSame($relationship, $relationship->addable());
        self::assertSame($relationship, $relationship->removable());
        self::assertSame($relationship, $relationship->defaultSort('-createdAt'));
        self::assertSame($relationship, $relationship->pagination(5, 50));
    }

    public function testCarriesWhatTheDeclarationStates(): void
    {
        $relationship = ToMany::make('tags', 'tags')
            ->replaceable()
            ->addable()
            ->removable()
            ->defaultSort('-createdAt,name')
            ->pagination(5, 50)
            ->build();

        self::assertSame('tags', $relationship->name);
        self::assertSame(Cardinality::ToMany, $relationship->cardinality);
        self::assertSame('tags', $relationship->relatedType);
        self::assertFalse($relationship->readsRelatedId());
        self::assertFalse($relationship->nullable);
        self::assertSame(
            [RelationshipUpdate::Replace, RelationshipUpdate::Add, RelationshipUpdate::Remove],
            $relationship->updates,
        );
        self::assertSame('-createdAt,name', $relationship->defaultSort?->toString());
        self::assertSame(
            [5, 50],
            [$relationship->paginationPolicy?->defaultSize, $relationship->paginationPolicy?->maxSize],
        );
    }

    public function testAToManyRelationshipIsNeverNullAndDeclaresNoSortAndNoPolicyUntilItWritesOne(): void
    {
        $relationship = ToMany::make('tags', 'tags')->build();

        self::assertFalse($relationship->nullable);
        self::assertSame([], $relationship->updates);
        self::assertNull($relationship->defaultSort);
        self::assertNull($relationship->paginationPolicy);
    }

    public function testTheUpdatesKeepTheOrderReplaceAddRemoveAndNotTheOrderOfTheDeclaration(): void
    {
        $relationship = ToMany::make('tags', 'tags')->removable()->addable()->replaceable()->build();

        self::assertSame(
            [RelationshipUpdate::Replace, RelationshipUpdate::Add, RelationshipUpdate::Remove],
            $relationship->updates,
        );
    }

    /**
     * the empty text is refused too: a sort that is not declared is null, never empty
     */
    #[DataProvider('impossibleSorts')]
    public function testRefusesADefaultSortThatIsNoSortWhereItIsWritten(string $sort, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ToMany::make('tags', 'tags')->defaultSort($sort);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function impossibleSorts(): iterable
    {
        yield 'the empty text' => ['', 'sort field "" is no field path'];
        yield 'an empty field behind a comma' => ['name,', 'sort field "" is no field path'];
        yield 'a second minus' => ['--name', 'sort field "--name" is no field path'];
        yield 'one field twice' => ['name,-name', 'field "name" is sorted by twice in one sort'];
    }

    #[DataProvider('impossiblePolicies')]
    public function testRefusesAPageSizeNoPolicyCanHaveWhereItIsWritten(
        int $defaultSize,
        int $maxSize,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ToMany::make('tags', 'tags')->pagination($defaultSize, $maxSize);
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function impossiblePolicies(): iterable
    {
        yield 'a default size below 1' => [0, 50, 'the default page size is 0, and it is at least 1'];
        yield 'a default size above the maximum' => [
            50,
            5,
            'the default page size 50 is above the maximum page size 5',
        ];
    }

    /**
     * @param Closure(ToMany): mixed $second
     */
    #[DataProvider('partsWrittenTwice')]
    public function testRefusesAPartOfTheDeclarationWrittenTwice(Closure $second, string $message): void
    {
        $relationship = ToMany::make('tags', 'tags')
            ->defaultSort('-createdAt')
            ->pagination(5, 50);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $second($relationship);
    }

    /**
     * @return iterable<string, array{Closure(ToMany): mixed, string}>
     */
    public static function partsWrittenTwice(): iterable
    {
        yield 'a default sort' => [
            static function (ToMany $relationship): ToMany {
                return $relationship->defaultSort('name');
            },
            'relationship "tags" declares a default sort twice, and each part of a declaration is written once',
        ];
        yield 'a pagination policy' => [
            static function (ToMany $relationship): ToMany {
                return $relationship->pagination(10, 100);
            },
            'relationship "tags" declares a pagination policy twice, and each part of a declaration is written once',
        ];
    }
}
