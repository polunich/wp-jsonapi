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

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DateTimeCodec;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\IdMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Parsing\CursorConditions;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Filter\AllOf;
use Polunich\WpJsonApi\Query\Filter\AnyOf;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Sort\Direction;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Sort\SortField;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Parsing\CollectedProblems;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(CursorConditions::class)]
final class CursorConditionsTest extends TestCase
{
    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(CursorConditions::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testARequestWithoutACursorAddsNoCondition(): void
    {
        $errors = self::errorList();

        self::assertNull(self::cursorConditions()->of(PageRequest::cursor(), 'pets', self::byId(), $errors));
        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testACursorOnTheIdAloneIsOneComparison(): void
    {
        $errors = self::errorList();
        $conditions = self::cursorConditions()->of(
            PageRequest::cursor(after: new Cursor([], '7')),
            'pets',
            self::byId(),
            $errors,
        );

        self::assertEquals(new Condition(new FieldPath(['id']), '@gt', '7'), $conditions);
        self::assertSame([], CollectedProblems::of($errors));
    }

    /**
     * `created` sorts descending, so reading forward past it is `@lt`
     */
    public function testTheConditionsOfACursorAreTheChainOfTheSort(): void
    {
        $errors = self::errorList();
        $conditions = self::cursorConditions()->of(
            PageRequest::cursor(after: new Cursor(['created' => '2026-01-02T00:00:00Z'], '7')),
            'pets',
            self::byCreated(),
            $errors,
        );
        $created = new FieldPath(['created']);
        $moment = new DateTimeImmutable('2026-01-02T00:00:00Z');

        self::assertEquals(
            new AnyOf([
                new Condition($created, '@lt', $moment),
                new AllOf([
                    new Condition($created, '@eq', $moment),
                    new Condition(new FieldPath(['id']), '@gt', '7'),
                ]),
            ]),
            $conditions,
        );
        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testACursorThePageEndsAtTurnsTheComparisonsAround(): void
    {
        $errors = self::errorList();
        $conditions = self::cursorConditions()->of(
            PageRequest::cursor(before: new Cursor(['created' => '2026-01-02T00:00:00Z'], '7')),
            'pets',
            self::byCreated(),
            $errors,
        );
        $created = new FieldPath(['created']);
        $moment = new DateTimeImmutable('2026-01-02T00:00:00Z');

        self::assertEquals(
            new AnyOf([
                new Condition($created, '@gt', $moment),
                new AllOf([
                    new Condition($created, '@eq', $moment),
                    new Condition(new FieldPath(['id']), '@lt', '7'),
                ]),
            ]),
            $conditions,
        );
        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testARangeHoldsBothChainsAtOnce(): void
    {
        $errors = self::errorList();
        $conditions = self::cursorConditions()->of(
            PageRequest::cursor(after: new Cursor([], '2'), before: new Cursor([], '9')),
            'pets',
            self::byId(),
            $errors,
        );
        $id = new FieldPath(['id']);

        self::assertEquals(
            new AllOf([new Condition($id, '@gt', '2'), new Condition($id, '@lt', '9')]),
            $conditions,
        );
        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testEachValueIsDecodedByTheCodecOfItsField(): void
    {
        $errors = self::errorList();
        $conditions = self::cursorConditions()->of(
            PageRequest::cursor(after: new Cursor(['rank' => 5], '7')),
            'pets',
            new Sort([new SortField(new FieldPath(['rank'])), new SortField(new FieldPath(['id']))]),
            $errors,
        );
        $rank = new FieldPath(['rank']);

        self::assertEquals(
            new AnyOf([
                new Condition($rank, '@gt', 5),
                new AllOf([
                    new Condition($rank, '@eq', 5),
                    new Condition(new FieldPath(['id']), '@gt', '7'),
                ]),
            ]),
            $conditions,
        );
        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testAPathThroughAToOneRelationshipIsReadOnTheRelatedType(): void
    {
        $errors = self::errorList();
        $conditions = self::cursorConditions()->of(
            PageRequest::cursor(after: new Cursor(['owner.name' => 'Ada'], '7')),
            'pets',
            new Sort([new SortField(new FieldPath(['owner', 'name'])), new SortField(new FieldPath(['id']))]),
            $errors,
        );

        self::assertInstanceOf(AnyOf::class, $conditions);
        self::assertEquals(new Condition(new FieldPath(['owner', 'name']), '@gt', 'Ada'), $conditions->nodes[0]);
        self::assertSame([], CollectedProblems::of($errors));
    }

    /**
     * `customers` has the digits id codec of `Samples::id()`, which refuses `twelve`
     */
    public function testARelatedIdIsReadByTheIdCodecOfTheRelatedType(): void
    {
        $sort = new Sort([new SortField(new FieldPath(['owner', 'id'])), new SortField(new FieldPath(['id']))]);
        $errors = self::errorList();
        $conditions = self::cursorConditions()->of(
            PageRequest::cursor(after: new Cursor(['owner.id' => '12'], '7')),
            'pets',
            $sort,
            $errors,
        );

        self::assertInstanceOf(AnyOf::class, $conditions);
        self::assertEquals(new Condition(new FieldPath(['owner', 'id']), '@gt', '12'), $conditions->nodes[0]);
        self::assertSame([], CollectedProblems::of($errors));

        $refused = self::errorList();

        self::assertNull(self::cursorConditions()->of(
            PageRequest::cursor(after: new Cursor(['owner.id' => 'twelve'], '7')),
            'pets',
            $sort,
            $refused,
        ));
        self::assertSame(
            [[ErrorCode::InvalidQueryParameter->value, 'page[after]', ['name' => 'page[after]']]],
            CollectedProblems::of($refused),
        );
    }

    public function testTheIdOfACursorIsDecodedByTheIdCodec(): void
    {
        $errors = self::errorList();
        $conditions = self::cursorConditions()->of(
            PageRequest::cursor(after: new Cursor([], 'no uuid')),
            'invoices',
            self::byId(),
            $errors,
        );

        self::assertNull($conditions);
        self::assertSame(
            [[ErrorCode::InvalidQueryParameter->value, 'page[after]', ['name' => 'page[after]']]],
            CollectedProblems::of($errors),
        );
    }

    /**
     * @param array<array-key, bool|float|int|string> $values
     */
    #[DataProvider('cursorsOfAnotherRequest')]
    public function testACursorThatDoesNotMatchTheRequestIsRefused(array $values): void
    {
        $errors = self::errorList();
        $conditions = self::cursorConditions()->of(
            PageRequest::cursor(after: new Cursor($values, '7')),
            'pets',
            self::byCreated(),
            $errors,
        );

        self::assertNull($conditions);
        self::assertSame(
            [[ErrorCode::InvalidQueryParameter->value, 'page[after]', ['name' => 'page[after]']]],
            CollectedProblems::of($errors),
        );
    }

    /**
     * @return iterable<string, array{array<array-key, bool|float|int|string>}>
     */
    public static function cursorsOfAnotherRequest(): iterable
    {
        yield 'a field the sort does not read' => [['name' => 'a']];

        yield 'no value for a field the sort reads' => [[]];

        yield 'a field beside the ones the sort reads' => [['created' => '2026-01-02T00:00:00Z', 'name' => 'a']];

        yield 'the id among the sort values' => [['created' => '2026-01-02T00:00:00Z', 'id' => '7']];

        yield 'a value the codec of its field refuses' => [['created' => 'yesterday']];

        yield 'a value of another type' => [['created' => 5]];
    }

    public function testTheRefusalOfTheCodecIsKeptForTheLog(): void
    {
        $errors = self::errorList();

        self::cursorConditions()->of(
            PageRequest::cursor(after: new Cursor(['created' => 'yesterday'], '7')),
            'pets',
            self::byCreated(),
            $errors,
        );
        $exception = $errors->exception();

        self::assertNotNull($exception);
        self::assertInstanceOf(DecodeException::class, $exception->getPrevious());
    }

    /**
     * the partial chain is harmless: the 400 of the error list stops the request before it runs
     */
    public function testARangeKeepsTheChainOfTheCursorItCouldRead(): void
    {
        $errors = self::errorList();
        $conditions = self::cursorConditions()->of(
            PageRequest::cursor(after: new Cursor([], '2'), before: new Cursor(['name' => 'a'], '9')),
            'pets',
            self::byId(),
            $errors,
        );

        self::assertEquals(new Condition(new FieldPath(['id']), '@gt', '2'), $conditions);
        self::assertSame(
            [[ErrorCode::InvalidQueryParameter->value, 'page[before]', ['name' => 'page[before]']]],
            CollectedProblems::of($errors),
        );
    }

    #[DataProvider('otherStrategies')]
    public function testAPageOfAnotherStrategyIsRefused(PageRequest $page): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a cursor is translated for the cursor strategy alone');

        self::cursorConditions()->of($page, 'pets', self::byId(), self::errorList());
    }

    /**
     * @return iterable<string, array{PageRequest}>
     */
    public static function otherStrategies(): iterable
    {
        yield 'the page number' => [PageRequest::pageNumber(2)];

        yield 'the offset' => [PageRequest::offset(10, 5)];
    }

    public function testASortWithoutTheIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not hold id');

        self::cursorConditions()->of(
            PageRequest::cursor(after: new Cursor(['name' => 'a'], '7')),
            'pets',
            new Sort([new SortField(new FieldPath(['name']))]),
            self::errorList(),
        );
    }

    /**
     * @param list<string> $path
     */
    #[DataProvider('impossibleSorts')]
    public function testASortFieldTheTypeDoesNotDeclareIsRefused(array $path, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::cursorConditions()->of(
            PageRequest::cursor(after: new Cursor([implode('.', $path) => 'a'], '7')),
            'pets',
            new Sort([new SortField(new FieldPath($path)), new SortField(new FieldPath(['id']))]),
            self::errorList(),
        );
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function impossibleSorts(): iterable
    {
        yield 'an attribute the type does not declare' => [
            ['address'],
            'which is no sortable attribute of type "pets"',
        ];

        yield 'a path through a to-many relationship' => [['orders', 'address'], 'no to-one relationship'];

        yield 'a path through a relationship the type does not declare' => [
            ['breeder', 'name'],
            'no to-one relationship',
        ];
    }

    private static function cursorConditions(): CursorConditions
    {
        return new CursorConditions(self::registry(), NamingPolicy::SnakeCase);
    }

    private static function registry(): ResourceRegistry
    {
        return new ResourceRegistry([
            Samples::resource(
                'pets',
                attributes: [
                    Samples::attribute('name', sortable: true),
                    Samples::attribute('created', new DateTimeCodec(), sortable: true),
                    Samples::attribute('rank', new IntegerCodec(), sortable: true),
                ],
                relationships: [
                    Samples::toOne('owner', 'customers'),
                    Samples::toMany('orders', 'orders'),
                ],
                capabilities: [Capability::CollectionReader, Capability::RelatedLoader],
            ),
            Samples::resource('customers', attributes: [Samples::attribute('name', sortable: true)]),
            Samples::resource(
                'orders',
                attributes: [Samples::attribute('address')],
                capabilities: [Capability::CollectionReader],
            ),
            Samples::resource(
                'invoices',
                id: new IdMetadata(new UuidCodec(), static function (Pet $invoice): string {
                    return $invoice->id;
                }, true),
            ),
        ]);
    }

    private static function byId(): Sort
    {
        return new Sort([new SortField(new FieldPath(['id']))]);
    }

    private static function byCreated(): Sort
    {
        return new Sort([
            new SortField(new FieldPath(['created']), Direction::Descending),
            new SortField(new FieldPath(['id'])),
        ]);
    }

    private static function errorList(): ErrorList
    {
        return new ErrorList(new StatusSelector());
    }
}
