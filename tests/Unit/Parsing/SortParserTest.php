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

namespace Polunich\WpJsonApi\Tests\Unit\Parsing;

use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\NullableCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Parsing\SortParser;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;

/**
 * @internal
 */
#[CoversClass(SortParser::class)]
final class SortParserTest extends TestCase
{
    public function testReadsTheFieldsInTheOrderTheClientWroteThem(): void
    {
        $errors = self::errorList();

        $sort = self::parser()->parse('-name,owner.name', 'pets', Strategy::PageNumber, $errors);

        self::assertSame('-name,owner.name', $sort->toString());
        self::assertNull($errors->exception());
    }

    /**
     * `id` is no attribute of `pets`, yet every type sorts by it
     */
    public function testSortsByIdAlone(): void
    {
        $errors = self::errorList();

        $sort = self::parser()->parse('-id', 'pets', Strategy::PageNumber, $errors);

        self::assertSame('-id', $sort->toString());
        self::assertNull($errors->exception());
    }

    #[DataProvider('sortsThatAreNotRead')]
    public function testReturnsTheEmptySortWhereTheRequestWritesNoSortText(mixed $sort): void
    {
        $errors = self::errorList();

        $sort = self::parser()->parse($sort, 'pets', Strategy::PageNumber, $errors);

        self::assertTrue($sort->isEmpty());
        self::assertNull($errors->exception());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function sortsThatAreNotRead(): array
    {
        return [
            'no sort parameter' => [null],
            'the Strapi form sort[0]=name' => [['name']],
            'a value that is no text at all' => [1],
        ];
    }

    /**
     * @param string $read the fields still read beside the refusal
     */
    #[DataProvider('unreadableTexts')]
    public function testRefusesTextThatRepresentsNoSortFieldOnceForTheParameter(string $sort, string $read): void
    {
        $errors = self::errorList();

        $parsed = self::parser()->parse($sort, 'pets', Strategy::PageNumber, $errors);

        self::assertSame($read, $parsed->toString());
        self::assertSame([ErrorCode::InvalidQueryParameter->value], self::codes($errors));
        self::assertSame('sort', self::problems($errors)[0]->parameter);
        self::assertSame(['name' => 'sort'], self::problems($errors)[0]->parameters);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unreadableTexts(): array
    {
        return [
            'the empty text' => ['', ''],
            'two minus signs' => ['--name', ''],
            'an empty segment behind a field' => ['name,', 'name'],
            'two fields no sort field can be read from' => ['--name,a..b', ''],
        ];
    }

    #[DataProvider('repeats')]
    public function testRefusesAFieldTheSortNamesAgain(string $sort): void
    {
        $errors = self::errorList();

        $read = self::parser()->parse($sort, 'pets', Strategy::PageNumber, $errors);

        self::assertSame([ErrorCode::DuplicateSortField->value], self::codes($errors));
        self::assertSame(['field' => 'name'], self::problems($errors)[0]->parameters);
        self::assertSame('sort', self::problems($errors)[0]->parameter);
        self::assertSame(1, count($read->fields()));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function repeats(): array
    {
        return [
            'the same field twice' => ['name,name'],
            'the other direction' => ['-name,name'],
            'three times, reported once' => ['name,name,-name'],
        ];
    }

    #[DataProvider('unsupportedFields')]
    public function testRefusesAFieldTheRouteDoesNotSortBy(string $sort, string $field): void
    {
        $errors = self::errorList();

        $read = self::parser()->parse($sort, 'pets', Strategy::PageNumber, $errors);

        self::assertTrue($read->isEmpty());
        self::assertSame([ErrorCode::UnsupportedSort->value], self::codes($errors));
        self::assertSame(['field' => $field], self::problems($errors)[0]->parameters);
        self::assertSame('sort', self::problems($errors)[0]->parameter);
        self::assertNull(self::problems($errors)[0]->typeLink);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unsupportedFields(): array
    {
        return [
            'a field the type does not declare' => ['bogus', 'bogus'],
            'an attribute that is not sortable' => ['-description', 'description'],
            'a path through a to-many relationship' => ['orders.address', 'orders.address'],
            'a path through a relationship that is not declared' => ['bogus.name', 'bogus.name'],
            'a path that ends at a relationship' => ['owner', 'owner'],
            'the id behind two relationships' => ['owner.trainer.id', 'owner.trainer.id'],
            'the id behind a to-many relationship' => ['orders.id', 'orders.id'],
            'an attribute of the related type that is not sortable' => ['owner.bio', 'owner.bio'],
            'a path of two relationship segments' => ['owner.trainer.name', 'owner.trainer.name'],
            'a path of two relationship segments whose first is to-many' => [
                'orders.customer.name',
                'orders.customer.name',
            ],
        ];
    }

    /**
     * the depth is checked before the walk,
     * so a deeper path gets no type link of the profile, even under the cursor strategy.
     * `breeder.trainer.name` pins that order: its first segment may be empty,
     * which alone would earn the link
     */
    public function testSortsByOneRelationshipSegmentAndRefusesADeeperPath(): void
    {
        $parser = self::parser();
        $errors = self::errorList();

        $read = $parser->parse('owner.name', 'pets', Strategy::Cursor, $errors);

        self::assertSame('owner.name', $read->toString());
        self::assertNull($errors->exception());

        $deeper = self::errorList();
        $refused = $parser->parse('owner.trainer.name,owner.trainer.trainer.name', 'pets', Strategy::Cursor, $deeper);

        self::assertTrue($refused->isEmpty());
        self::assertSame([ErrorCode::UnsupportedSort->value, ErrorCode::UnsupportedSort->value], self::codes($deeper));
        self::assertSame(['field' => 'owner.trainer.name'], self::problems($deeper)[0]->parameters);
        self::assertSame(['field' => 'owner.trainer.trainer.name'], self::problems($deeper)[1]->parameters);
        self::assertNull(self::problems($deeper)[0]->typeLink);
        self::assertNull(self::problems($deeper)[1]->typeLink);

        $nullable = self::errorList();
        $refusedThroughNullable = $parser->parse('breeder.trainer.name', 'pets', Strategy::Cursor, $nullable);

        self::assertTrue($refusedThroughNullable->isEmpty());
        self::assertSame([ErrorCode::UnsupportedSort->value], self::codes($nullable));
        self::assertSame(['field' => 'breeder.trainer.name'], self::problems($nullable)[0]->parameters);
        self::assertNull(self::problems($nullable)[0]->typeLink);
    }

    #[DataProvider('nullablePaths')]
    public function testRefusesANullablePathUnderTheCursorStrategyWithTheTypeLinkOfTheProfile(string $sort): void
    {
        $errors = self::errorList();

        $read = self::parser()->parse($sort, 'pets', Strategy::Cursor, $errors);

        self::assertTrue($read->isEmpty());
        self::assertSame([ErrorCode::UnsupportedSort->value], self::codes($errors));
        self::assertSame(
            'https://jsonapi.org/profiles/ethanresnick/cursor-pagination/unsupported-sort',
            self::problems($errors)[0]->typeLink,
        );
        self::assertSame('sort', self::problems($errors)[0]->parameter);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nullablePaths(): array
    {
        return [
            'an attribute that may be null' => ['breed'],
            'an attribute of a related type that may be null' => ['owner.nickname'],
            'a to-one relationship that may be empty' => ['breeder.name'],
            'the id behind a to-one relationship that may be empty' => ['breeder.id'],
        ];
    }

    #[DataProvider('strategiesWithoutACursor')]
    public function testSortsByANullablePathUnderTheOtherStrategies(Strategy $strategy): void
    {
        $errors = self::errorList();

        $sort = self::parser()->parse('breed,breeder.name', 'pets', $strategy, $errors);

        self::assertSame('breed,breeder.name', $sort->toString());
        self::assertNull($errors->exception());
    }

    /**
     * @return array<string, array{Strategy}>
     */
    public static function strategiesWithoutACursor(): array
    {
        return [
            'the page number' => [Strategy::PageNumber],
            'the offset' => [Strategy::Offset],
        ];
    }

    public function testSortsByAPathThatHoldsNoNullUnderTheCursorStrategy(): void
    {
        $errors = self::errorList();

        $sort = self::parser()->parse('owner.name,-owner.id,id', 'pets', Strategy::Cursor, $errors);

        self::assertSame('owner.name,-owner.id,id', $sort->toString());
        self::assertNull($errors->exception());
    }

    #[DataProvider('unsortableFields')]
    public function testAFieldThatIsNotSortableCarriesNoTypeLinkUnderTheCursorStrategy(string $sort): void
    {
        $errors = self::errorList();

        self::parser()->parse($sort, 'pets', Strategy::Cursor, $errors);

        self::assertSame([ErrorCode::UnsupportedSort->value], self::codes($errors));
        self::assertNull(self::problems($errors)[0]->typeLink);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsortableFields(): iterable
    {
        yield 'an attribute that is not sortable' => ['description'];
        yield 'an attribute that is not sortable through a nullable relationship' => ['breeder.bio'];
    }

    public function testCollectsEveryMistakeOfTheSortAndKeepsTheFieldsItRead(): void
    {
        $errors = self::errorList();

        $sort = self::parser()->parse('bogus,description,name,name,--x', 'pets', Strategy::PageNumber, $errors);

        self::assertSame('name', $sort->toString());
        self::assertSame(
            [
                ErrorCode::UnsupportedSort->value,
                ErrorCode::UnsupportedSort->value,
                ErrorCode::DuplicateSortField->value,
                ErrorCode::InvalidQueryParameter->value,
            ],
            self::codes($errors),
        );
        self::assertSame(400, $errors->exception()?->status());
    }

    public function testRefusesATypeThatIsNotDeclared(): void
    {
        $this->expectException(OutOfBoundsException::class);

        self::parser()->parse('name', 'ghosts', Strategy::PageNumber, self::errorList());
    }

    /**
     * `owner` is never empty, `breeder` may be; `breed` and `nickname` may be null.
     * `trainer` leads from `customers` back to `customers` and is never empty,
     * so `owner.trainer.name` fails on its depth alone, and paths of any depth exist
     */
    private static function parser(): SortParser
    {
        $customers = Samples::resource('customers', attributes: [
            Samples::attribute('name', sortable: true),
            Samples::attribute('bio'),
            Samples::attribute('nickname', codec: new NullableCodec(new StringCodec()), sortable: true),
        ], relationships: [Samples::toOne('trainer', 'customers', nullable: false)]);

        $pets = Samples::resource(
            'pets',
            attributes: [
                Samples::attribute('name', sortable: true),
                Samples::attribute('description'),
                Samples::attribute('breed', codec: new NullableCodec(new StringCodec()), sortable: true),
            ],
            relationships: [
                Samples::toOne('owner', 'customers', nullable: false),
                Samples::toOne('breeder', 'customers'),
                Samples::toMany('orders', 'orders', includable: false),
            ],
            capabilities: [Capability::CollectionReader],
        );

        // sortable, so `orders.address` fails on the to-many cardinality alone
        $orders = Samples::resource(
            'orders',
            attributes: [Samples::attribute('address', sortable: true)],
            capabilities: [Capability::CollectionReader],
        );

        return new SortParser(new ResourceRegistry([$pets, $customers, $orders]));
    }

    private static function errorList(): ErrorList
    {
        return new ErrorList(new StatusSelector());
    }

    /**
     * @return list<ErrorDescription>
     */
    private static function problems(ErrorList $errors): array
    {
        $exception = $errors->exception();

        return $exception === null ? [] : $exception->descriptions();
    }

    /**
     * @return list<string>
     */
    private static function codes(ErrorList $errors): array
    {
        return array_map(
            static function (ErrorDescription $problem): string {
                return $problem->code instanceof ErrorCode
                    ? $problem->code->value
                    : $problem->code;
            },
            self::problems($errors),
        );
    }
}
