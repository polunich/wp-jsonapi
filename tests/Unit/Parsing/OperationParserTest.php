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

use Closure;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\AcceptLanguageParser;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\IdMetadata;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\WriteRule;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\FetchCollection;
use Polunich\WpJsonApi\Operation\FetchRelated;
use Polunich\WpJsonApi\Operation\FetchRelationship;
use Polunich\WpJsonApi\Operation\FetchResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\PendingWrite;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Parsing\CollectionReadingParser;
use Polunich\WpJsonApi\Parsing\CursorConditions;
use Polunich\WpJsonApi\Parsing\CursorProfile;
use Polunich\WpJsonApi\Parsing\DocumentParser;
use Polunich\WpJsonApi\Parsing\FieldsetParser;
use Polunich\WpJsonApi\Parsing\FilterParser;
use Polunich\WpJsonApi\Parsing\IncludeParser;
use Polunich\WpJsonApi\Parsing\JsonBodyDecoder;
use Polunich\WpJsonApi\Parsing\Negotiation;
use Polunich\WpJsonApi\Parsing\OperationParser;
use Polunich\WpJsonApi\Parsing\PageParser;
use Polunich\WpJsonApi\Parsing\QueryParameterGuard;
use Polunich\WpJsonApi\Parsing\SortParser;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Filter\AllOf;
use Polunich\WpJsonApi\Query\Filter\AnyOf;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\CursorText;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\RouteTarget;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\TrimmingCodec;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;

/**
 * @internal
 */
#[CoversClass(OperationParser::class)]
final class OperationParserTest extends TestCase
{
    private const JSON_API = 'application/vnd.api+json';

    public function testACollectionRequestCarriesFilterSortPageIncludeAndFields(): void
    {
        $request = self::request('GET', [
            'filter' => ['name' => ['@eq' => 'Ember Hamster']],
            'sort' => '-name',
            'page' => ['number' => '2', 'size' => '5'],
            'include' => 'owner',
            'fields' => ['pets' => 'name'],
        ]);

        $operation = self::parse(self::parser(), $request, self::collection(), self::negotiation());

        self::assertInstanceOf(FetchCollection::class, $operation);
        self::assertSame('pets', $operation->type);
        self::assertInstanceOf(Condition::class, $operation->filter);
        self::assertSame('-name', $operation->sort->toString());
        self::assertSame(2, $operation->page->number);
        self::assertSame(5, $operation->page->size);
        self::assertSame('owner', $operation->include?->toString());
        self::assertSame(['name'], $operation->fields->fields('pets'));
    }

    public function testAPlainCollectionRequestCarriesTheValuesOfARequestWithoutTheFamilies(): void
    {
        $operation = self::parse(self::parser(), self::request('GET'), self::collection(), self::negotiation());

        self::assertInstanceOf(FetchCollection::class, $operation);
        self::assertNull($operation->filter);
        self::assertTrue($operation->sort->isEmpty());
        self::assertSame(Strategy::PageNumber, $operation->page->strategy);
        self::assertNull($operation->include);
        self::assertTrue($operation->fields->isEmpty());
    }

    public function testAResourceRequestTargetsTheResourceOfTheRoute(): void
    {
        $request = self::request('GET', ['include' => 'owner.pets', 'fields' => ['customers' => 'name']]);

        $operation = self::parse(self::parser(), $request, self::resourceRoute(), self::negotiation());

        self::assertInstanceOf(FetchResource::class, $operation);
        self::assertSame('pets', $operation->target->type);
        self::assertSame('7', $operation->target->id);
        self::assertSame('owner.pets', $operation->include?->toString());
        self::assertSame(['name'], $operation->fields->fields('customers'));
    }

    public function testARelatedRequestOfAToManyRelationshipReadsTheQueryParameterFamiliesOfTheRelatedType(): void
    {
        $request = self::request('GET', [
            'filter' => ['description' => ['@eq' => 'yes']],
            'sort' => 'description',
            'page' => ['offset' => '10', 'limit' => '5'],
            'include' => 'customer',
            'fields' => ['orders' => 'description'],
        ]);

        $operation = self::parse(self::parser(), $request, self::related('orders'), self::negotiation());

        self::assertInstanceOf(FetchRelated::class, $operation);
        self::assertSame('pets', $operation->parent->type);
        self::assertSame('orders', $operation->relationship);
        self::assertInstanceOf(Condition::class, $operation->filter);
        self::assertSame('description', $operation->sort->toString());
        self::assertSame(10, $operation->page->offset);
        // `customer` is a relationship of `orders` alone,
        // so the path proves the paths start from the related type
        self::assertSame('customer', $operation->include?->toString());
        self::assertSame(['description'], $operation->fields->fields('orders'));
    }

    public function testARelatedRequestOfAToOneRelationshipCarriesNoFilterSortOrPage(): void
    {
        $request = self::request('GET', ['include' => 'pets', 'fields' => ['customers' => 'name']]);

        $operation = self::parse(self::parser(), $request, self::related('owner'), self::negotiation());

        self::assertInstanceOf(FetchRelated::class, $operation);
        self::assertNull($operation->filter);
        self::assertTrue($operation->sort->isEmpty());
        self::assertSame(Strategy::PageNumber, $operation->page->strategy);
        // `pets` is a relationship of `customers`, the related type of `owner`
        self::assertSame('pets', $operation->include?->toString());
        self::assertSame(['name'], $operation->fields->fields('customers'));
    }

    public function testARelationshipRequestReadsPageAndIncludePathsFromTheParentType(): void
    {
        $request = self::request('GET', [
            'page' => ['number' => '3'],
            'include' => 'orders.customer',
            'fields' => ['orders' => 'description'],
        ]);

        $operation = self::parse(self::parser(), $request, self::relationship('orders'), self::negotiation());

        self::assertInstanceOf(FetchRelationship::class, $operation);
        self::assertSame('orders', $operation->relationship);
        self::assertSame(3, $operation->page->number);
        self::assertSame('orders.customer', $operation->include?->toString());
        self::assertSame(['description'], $operation->fields->fields('orders'));
    }

    #[DataProvider('includePathsOfAnotherRoute')]
    public function testRefusesAnIncludePathOfAnotherRoute(RouteTarget $target, string $path): void
    {
        self::assertSame(
            [400, [['unsupported_include', ['path' => $path], 'include']]],
            self::refusal(self::request('GET', ['include' => $path]), $target),
        );
    }

    public function testARelationshipWriteRefusesAnIncludePathOfAnotherRelationship(): void
    {
        $request = self::request('POST', ['include' => 'owner'], '{"data": []}');

        self::assertSame(
            [400, [['unsupported_include', ['path' => 'owner'], 'include']]],
            self::refusal($request, self::relationship('orders')),
        );
    }

    /**
     * @return array<string, array{RouteTarget, string}>
     */
    public static function includePathsOfAnotherRoute(): array
    {
        return [
            'a relationship of the parent type the relationship route does not name' => [
                self::relationship('orders'),
                'owner',
            ],
            'a relationship of the parent type on the related route' => [
                self::related('orders'),
                'orders',
            ],
        ];
    }

    public function testACreateReadsTheResourceObjectOfTheBody(): void
    {
        $request = self::request('POST', ['include' => 'owner', 'fields' => ['pets' => 'name']], '{
            "data": {
                "type": "pets",
                "attributes": {"name": "Ember Hamster"},
                "relationships": {"owner": {"data": {"type": "customers", "id": "9"}}}
            }
        }');

        $operation = self::write(self::parser(), $request, self::collection());

        self::assertInstanceOf(CreateResource::class, $operation);
        self::assertSame('pets', $operation->type);
        self::assertNull($operation->id);
        self::assertSame('Ember Hamster', $operation->attributes->get('name'));
        self::assertSame('9', $operation->relationships->get('owner')->identifier()?->id);
        self::assertSame('owner', $operation->include?->toString());
        self::assertSame(['name'], $operation->fields->fields('pets'));
    }

    public function testTheLidOfASinglePostIsDropped(): void
    {
        $request = self::request('POST', [], '{"data": {"type": "pets", "lid": "draft"}}');

        $operation = self::write(self::parser(), $request, self::collection());

        self::assertInstanceOf(CreateResource::class, $operation);
        self::assertNull($operation->id);
    }

    public function testAnUpdateTargetsTheResourceOfTheRouteAndCarriesNoPrecondition(): void
    {
        $request = self::request('PATCH', ['include' => 'owner', 'fields' => ['customers' => 'name']], '{
            "data": {"type": "pets", "id": "7", "attributes": {"name": "Rex"}}
        }');

        $operation = self::write(self::parser(), $request, self::resourceRoute());

        self::assertInstanceOf(UpdateResource::class, $operation);
        self::assertSame('7', $operation->target->id);
        self::assertSame('Rex', $operation->attributes->get('name'));
        self::assertNull($operation->precondition);
        self::assertSame('owner', $operation->include?->toString());
        self::assertSame(['name'], $operation->fields->fields('customers'));
    }

    public function testADeleteCarriesTheTargetAndReadsNoContent(): void
    {
        $operation = self::parse(self::parser(), self::request('DELETE'), self::resourceRoute(), self::negotiation());

        self::assertInstanceOf(DeleteResource::class, $operation);
        self::assertSame('pets', $operation->target->type);
        self::assertSame('7', $operation->target->id);
        self::assertNull($operation->precondition);
    }

    /**
     * the body is no JSON text, so any read of it would fail the test
     *
     * @param ?string $id the id of the target or the parent, null for a create
     */
    #[DataProvider('writesOfTheRoutes')]
    public function testParseReturnsAPendingWriteAndReadsNoContent(
        string $method,
        string $route,
        OperationKind $kind,
        ?string $id,
        ?string $relationship,
    ): void {
        $request = self::request($method, ['include' => 'orders', 'fields' => ['orders' => 'description']], 'not json');

        $write = self::parse(self::parser(), $request, self::routeOf($route), self::negotiation());

        self::assertInstanceOf(PendingWrite::class, $write);
        self::assertSame($kind, $write->kind);
        self::assertSame('pets', $write->type);
        self::assertSame($id, $write->target?->id);
        self::assertSame($relationship, $write->relationship);
        self::assertSame('orders', $write->include?->toString());
        self::assertSame(['description'], $write->fields->fields('orders'));
    }

    /**
     * @return array<string, array{string, string, OperationKind, ?string, ?string}>
     */
    public static function writesOfTheRoutes(): array
    {
        return [
            'the create' => ['POST', 'collection', OperationKind::CreateResource, null, null],
            'the update' => ['PATCH', 'resource', OperationKind::UpdateResource, '7', null],
            'the replacement of a relationship' => [
                'PATCH',
                'relationship',
                OperationKind::ReplaceRelationship,
                '7',
                'orders',
            ],
            'the addition to a relationship' => [
                'POST',
                'relationship',
                OperationKind::AddToRelationship,
                '7',
                'orders',
            ],
            'the removal from a relationship' => [
                'DELETE',
                'relationship',
                OperationKind::RemoveFromRelationship,
                '7',
                'orders',
            ],
        ];
    }

    public function testParseRefusesAQueryParameterOfAWriteBeforeItsTargetIsLoaded(): void
    {
        $request = self::request('POST', ['include' => 'nowhere'], 'not json');

        self::assertSame(
            [400, [['unsupported_include', ['path' => 'nowhere'], 'include']]],
            self::refusal($request, self::collection()),
        );
    }

    /**
     * @param class-string<object> $expected
     */
    #[DataProvider('relationshipWrites')]
    public function testARelationshipWriteReadsTheLinkageOfItsBody(
        string $method,
        string $body,
        string $expected,
        int $members,
    ): void {
        $request = self::request($method, ['include' => 'orders', 'fields' => ['orders' => 'description']], $body);

        $operation = self::write(self::parser(), $request, self::relationship('orders'));

        self::assertInstanceOf($expected, $operation);
        self::assertSame('orders', $operation->relationship());
        self::assertCount($members, self::linkageOf($operation));
        self::assertSame(['orders', ['description']], self::includeAndFieldsetOf($operation, 'orders'));
    }

    /**
     * @return array<string, array{string, string, class-string<object>, int}>
     */
    public static function relationshipWrites(): array
    {
        return [
            'PATCH replaces every member' => [
                'PATCH',
                '{"data": [{"type": "orders", "id": "2"}, {"type": "orders", "id": "3"}]}',
                ReplaceRelationship::class,
                2,
            ],
            'POST adds members' => [
                'POST',
                '{"data": [{"type": "orders", "id": "2"}]}',
                AddToRelationship::class,
                1,
            ],
            'DELETE removes members' => [
                'DELETE',
                '{"data": [{"type": "orders", "id": "2"}]}',
                RemoveFromRelationship::class,
                1,
            ],
        ];
    }

    public function testHeadReadsAsGet(): void
    {
        $operation = self::parse(self::parser(), self::request('HEAD'), self::collection(), self::negotiation());

        self::assertInstanceOf(FetchCollection::class, $operation);
        self::assertSame(OperationKind::FetchCollection, $operation->kind());
    }

    public function testEveryOperationCarriesTheLanguagesOfTheRequest(): void
    {
        $request = new ServerRequest('GET', new Headers(['Accept-Language' => 'uk, en;q=0.5']), [], [], '');

        $operation = self::parse(self::parser(), $request, self::collection(), self::negotiation());

        self::assertInstanceOf(FetchCollection::class, $operation);
        self::assertSame('uk', $operation->languages->select(['en', 'uk']));
    }

    public function testTheDefaultStrategyFollowsTheProfileTheAcceptFieldAskedFor(): void
    {
        $operation = self::parse(self::parser(), self::request('GET'), self::collection(), self::cursorNegotiation());

        self::assertInstanceOf(FetchCollection::class, $operation);
        self::assertSame(Strategy::Cursor, $operation->page->strategy);
    }

    public function testAProfileTheCursorPageAddsDoesNotChangeTheDefaultStrategy(): void
    {
        $applied = new Negotiation(MediaType::jsonApi([], [CursorProfile::URI]));

        $operation = self::parse(self::parser(), self::request('GET'), self::collection(), $applied);

        self::assertInstanceOf(FetchCollection::class, $operation);
        self::assertSame(Strategy::PageNumber, $operation->page->strategy);
    }

    public function testTheIdOfARouteIsReadByTheIdCodecOfItsType(): void
    {
        $target = new RouteTarget(RouteKind::Resource, 'documents', 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE', null);

        $operation = self::parse(self::parser(), self::request('GET'), $target, self::negotiation());

        self::assertInstanceOf(FetchResource::class, $operation);
        self::assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $operation->target->id);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('routesThatCarryAnId')]
    public function testARouteIdTheCodecRefusesIsNotFoundAndNamesNoSource(
        string $method,
        RouteTarget $target,
        array $query,
        string $body,
    ): void {
        self::assertSame(
            [404, [['not_found', [], null]]],
            self::problems(
                static function (
                    OperationParser $parser,
                ) use ($body, $method, $query, $target): Operation|PendingWrite {
                    return $body === ''
                        ? self::parse($parser, self::request($method, $query), $target, self::negotiation())
                        : self::write($parser, self::request($method, $query, $body), $target);
                },
            ),
        );
    }

    /**
     * @return iterable<string, array{string, RouteTarget, array<array-key, mixed>, string}>
     */
    public static function routesThatCarryAnId(): iterable
    {
        $refused = 'not-a-uuid';

        yield 'the resource route of a fetch' => [
            'GET',
            new RouteTarget(RouteKind::Resource, 'documents', $refused, null),
            [],
            '',
        ];
        yield 'the resource route of a delete' => [
            'DELETE',
            new RouteTarget(RouteKind::Resource, 'documents', $refused, null),
            [],
            '',
        ];
        yield 'the resource route of an update' => [
            'PATCH',
            new RouteTarget(RouteKind::Resource, 'documents', $refused, null),
            [],
            '{"data": {"type": "documents", "id": "' . $refused . '"}}',
        ];
    }

    public function testARouteIdThatDecodesToTheEmptyStringIsNotFound(): void
    {
        $target = new RouteTarget(RouteKind::Resource, 'notes', '  ', null);

        self::assertSame(
            [404, [['not_found', [], null]]],
            self::problems(
                static function (OperationParser $parser) use ($target): Operation|PendingWrite {
                    return self::parse(
                        $parser,
                        self::request('GET'),
                        $target,
                        self::negotiation(),
                    );
                },
            ),
        );
    }

    public function testTheRouteIdIsReadBeforeTheQueryParameters(): void
    {
        $target = new RouteTarget(RouteKind::Resource, 'documents', 'not-a-uuid', null);

        self::assertSame(
            [404, [['not_found', [], null]]],
            self::problems(
                static function (OperationParser $parser) use ($target): Operation|PendingWrite {
                    return self::parse(
                        $parser,
                        self::request('GET', ['sort' => 'title', 'nowhere' => '1']),
                        $target,
                        self::negotiation(),
                    );
                },
            ),
        );
    }

    public function testTheDecodeOfARouteIdKeepsTheRefusalAsThePreviousException(): void
    {
        $target = new RouteTarget(RouteKind::Resource, 'documents', 'not-a-uuid', null);

        try {
            self::parse(self::parser(), self::request('GET'), $target, self::negotiation());
        } catch (JsonApiException $exception) {
            self::assertInstanceOf(DecodeException::class, $exception->getPrevious());

            return;
        }

        self::fail('the parser accepted a route id its codec refuses');
    }

    public function testTheRouteIdOfAnUndeclaredTypeIsOutOfBounds(): void
    {
        $this->expectException(OutOfBoundsException::class);

        self::parse(
            self::parser(),
            self::request('GET'),
            new RouteTarget(RouteKind::Resource, 'ghosts', '7', null),
            self::negotiation(),
        );
    }

    public function testTheCursorsOfAPageBecomeConditionsOfTheFilterTree(): void
    {
        $request = self::request('GET', [
            'sort' => 'name',
            'filter' => ['name' => ['@eq' => 'Ember Hamster']],
            'page' => ['after' => CursorText::encode(new Cursor(['name' => 'a'], '7'))],
        ]);

        $operation = self::parse(self::parser(), $request, self::collection(), self::negotiation());

        self::assertInstanceOf(FetchCollection::class, $operation);
        self::assertEquals(
            new AllOf([
                new Condition(new FieldPath(['name']), '@eq', 'Ember Hamster'),
                self::chain('name', 'a', '@gt', '7', '@gt'),
            ]),
            $operation->filter,
        );
    }

    /**
     * the default sort is `-name`, so reading forward past the cursor is `@lt` on `name`
     * and `@gt` on the appended `id`
     *
     * @param array<string, string> $page
     */
    #[DataProvider('cursorPagesOfACollection')]
    public function testACursorOfARequestWithoutASortIsReadAgainstTheDeclaredDefaultSort(
        array $page,
        FilterNode $expected,
    ): void {
        $operation = self::parse(
            self::parser(),
            self::request('GET', ['page' => $page]),
            self::collection(),
            self::negotiation(),
        );

        self::assertInstanceOf(FetchCollection::class, $operation);
        self::assertEquals($expected, $operation->filter);
    }

    /**
     * @return iterable<string, array{array<string, string>, FilterNode}>
     */
    public static function cursorPagesOfACollection(): iterable
    {
        $after = CursorText::encode(new Cursor(['name' => 'a'], '7'));
        $before = CursorText::encode(new Cursor(['name' => 'z'], '9'));

        yield 'page[after]' => [['after' => $after], self::chain('name', 'a', '@lt', '7', '@gt')];
        yield 'page[before]' => [['before' => $before], self::chain('name', 'z', '@gt', '9', '@lt')];
        yield 'a range' => [
            ['after' => $after, 'before' => $before],
            new AllOf([
                self::chain('name', 'a', '@lt', '7', '@gt'),
                self::chain('name', 'z', '@gt', '9', '@lt'),
            ]),
        ];
    }

    /**
     * a cursor without sort values belongs to the sort of `id` alone, not to the default `-name`
     */
    public function testACursorKeyedByAnotherSortThanTheDefaultOneIsRefused(): void
    {
        $request = self::request('GET', ['page' => ['after' => CursorText::encode(new Cursor([], '7'))]]);

        self::assertSame(
            [400, [['invalid_query_parameter', ['name' => 'page[after]'], 'page[after]']]],
            self::refusal($request, self::collection()),
        );
    }

    #[DataProvider('routesOfAToManyRelationship')]
    public function testACursorOfARelationshipIsReadAgainstTheDefaultSortOfThatRelationship(string $route): void
    {
        $target = self::routeOf($route);
        $accepted = self::request(
            'GET',
            ['page' => ['after' => CursorText::encode(new Cursor(['description' => 'a'], '7'))]],
        );

        $operation = self::parse(self::parser(), $accepted, $target, self::negotiation());

        self::assertInstanceOf(Operation::class, $operation);
        self::assertEquals(self::chain('description', 'a', '@gt', '7', '@gt'), self::filterOf($operation));

        $refused = self::request('GET', ['page' => ['after' => CursorText::encode(new Cursor([], '7'))]]);

        self::assertSame(
            [400, [['invalid_query_parameter', ['name' => 'page[after]'], 'page[after]']]],
            self::refusal($refused, $target),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function routesOfAToManyRelationship(): array
    {
        return [
            'the related route' => ['related'],
            'the relationship route' => ['relationship'],
        ];
    }

    /**
     * @param array<string, string> $page
     */
    #[DataProvider('cursorPagesOfARelationship')]
    public function testTheCursorsOfARelationshipPageBecomeTheFilterOfItsOperation(
        array $page,
        FilterNode $expected,
    ): void {
        $request = self::request('GET', ['page' => $page]);

        $operation = self::parse(self::parser(), $request, self::relationship('orders'), self::negotiation());

        self::assertInstanceOf(FetchRelationship::class, $operation);
        self::assertEquals($expected, $operation->filter);
    }

    /**
     * @return iterable<string, array{array<string, string>, FilterNode}>
     */
    public static function cursorPagesOfARelationship(): iterable
    {
        $after = CursorText::encode(new Cursor(['description' => 'a'], '7'));
        $before = CursorText::encode(new Cursor(['description' => 'z'], '9'));

        yield 'page[after]' => [['after' => $after], self::chain('description', 'a', '@gt', '7', '@gt')];
        yield 'page[before]' => [['before' => $before], self::chain('description', 'z', '@lt', '9', '@lt')];
        yield 'a range' => [
            ['after' => $after, 'before' => $before],
            new AllOf([
                self::chain('description', 'a', '@gt', '7', '@gt'),
                self::chain('description', 'z', '@lt', '9', '@lt'),
            ]),
        ];
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('relationshipRequestsWithoutACursor')]
    public function testARelationshipRequestWithoutACursorCarriesNoFilter(string $relationship, array $query): void
    {
        $operation = self::parse(
            self::parser(),
            self::request('GET', $query),
            self::relationship($relationship),
            self::negotiation(),
        );

        self::assertInstanceOf(FetchRelationship::class, $operation);
        self::assertNull($operation->filter);
    }

    /**
     * @return array<string, array{string, array<array-key, mixed>}>
     */
    public static function relationshipRequestsWithoutACursor(): array
    {
        return [
            'the page number of a to-many relationship' => ['orders', ['page' => ['number' => '2']]],
            'a to-many relationship without a page' => ['orders', []],
            'a to-one relationship, which reads no page' => ['owner', []],
        ];
    }

    /**
     * `FetchRelationship` refuses a cursor page without its conditions,
     * so the operation must not be built once the phase found a mistake
     */
    public function testARelationshipRequestWithACursorAndAnotherMistakeIsRefusedForThatMistake(): void
    {
        $request = self::request('GET', [
            'include' => 'owner',
            'page' => ['after' => CursorText::encode(new Cursor(['description' => 'a'], '7'))],
        ]);

        self::assertSame(
            [400, [['unsupported_include', ['path' => 'owner'], 'include']]],
            self::refusal($request, self::relationship('orders')),
        );
    }

    public function testACursorIsNotReadAgainstAnotherSortWhenTheSortOfTheRequestWasRefused(): void
    {
        $request = self::request('GET', [
            'sort' => 'nowhere',
            'page' => ['after' => CursorText::encode(new Cursor(['nowhere' => 'a'], '7'))],
        ]);

        self::assertSame(
            [400, [['unsupported_sort', ['field' => 'nowhere'], 'sort']]],
            self::refusal($request, self::collection()),
        );
    }

    /**
     * the collection route pages by the policy of its type,
     * the routes of a relationship by the one the relationship declares
     */
    #[DataProvider('pagedRoutes')]
    public function testEachRouteBoundsTheSizeByItsOwnPolicy(RouteTarget $target, int $maxSize): void
    {
        $size = (string) ($maxSize + 1);

        self::assertSame(
            [400, [['max_page_size_exceeded', ['size' => $size, 'maxSize' => $maxSize], 'page[size]']]],
            self::refusal(self::request('GET', ['page' => ['size' => $size]]), $target),
        );
    }

    /**
     * @return iterable<string, array{RouteTarget, int}>
     */
    public static function pagedRoutes(): iterable
    {
        yield 'the collection route' => [self::collection(), 50];
        yield 'the related route' => [self::related('orders'), 25];
        yield 'the relationship route' => [self::relationship('orders'), 25];
    }

    public function testTheMistakesOfTheCollectionParametersFollowThoseOfTheDocument(): void
    {
        $request = self::request('GET', ['include' => 'nowhere', 'sort' => 'nowhere']);

        self::assertSame(
            [
                400,
                [
                    ['unsupported_include', ['path' => 'nowhere'], 'include'],
                    ['unsupported_sort', ['field' => 'nowhere'], 'sort'],
                ],
            ],
            self::refusal($request, self::collection()),
        );
    }

    public function testACursorThatDoesNotMatchTheSortIsRefused(): void
    {
        $request = self::request('GET', [
            'sort' => 'name',
            'page' => ['after' => CursorText::encode(new Cursor(['description' => 'a'], '7'))],
        ]);

        self::assertSame(
            [400, [['invalid_query_parameter', ['name' => 'page[after]'], 'page[after]']]],
            self::refusal($request, self::collection()),
        );
    }

    public function testRefusesEveryQueryParameterTheRouteDoesNotProcessAtOnce(): void
    {
        $request = self::request('GET', ['sort' => 'name', 'filter' => ['name' => ['@eq' => 'x']]]);

        self::assertSame(
            [400, [
                ['unsupported_query_parameter', ['name' => 'sort'], 'sort'],
                ['unsupported_query_parameter', ['name' => 'filter[name][@eq]'], 'filter[name][@eq]'],
            ]],
            self::refusal($request, self::resourceRoute()),
        );
    }

    /**
     * the body is no JSON text; reading it would add `invalid_json` to the response
     */
    public function testTheContentIsReadOnlyWhenTheQueryParametersHoldNoError(): void
    {
        $request = self::request('POST', ['sort' => 'name'], 'not json');

        self::assertSame(
            [400, [['unsupported_query_parameter', ['name' => 'sort'], 'sort']]],
            self::contentRefusal($request, self::collection()),
        );
    }

    public function testABodyThatIsNoJsonTextIsRefusedWithInvalidJson(): void
    {
        self::assertSame(
            [400, [['invalid_json', [], null]]],
            self::contentRefusal(self::request('POST', [], '{'), self::collection()),
        );
    }

    public function testCollectsEveryProblemOfTheContent(): void
    {
        $request = self::request('POST', [], '{
            "data": {"type": "pets", "attributes": {"nowhere": 1, "elsewhere": 2}}
        }');

        self::assertSame(
            [422, [
                ['unknown_field', ['type' => 'pets', 'field' => 'nowhere'], '/data/attributes/nowhere'],
                ['unknown_field', ['type' => 'pets', 'field' => 'elsewhere'], '/data/attributes/elsewhere'],
            ]],
            self::contentRefusal($request, self::collection()),
        );
    }

    public function testAnUpdateWhoseResourceObjectNamesAnotherResourceIsRefused(): void
    {
        $request = self::request('PATCH', [], '{"data": {"type": "pets", "id": "8"}}');

        self::assertSame(
            [409, [['id_mismatch', ['id' => '8', 'target' => '7'], '/data/id']]],
            self::contentRefusal($request, self::resourceRoute()),
        );
    }

    public function testRefusesTheAtomicRoute(): void
    {
        $target = new RouteTarget(RouteKind::Atomic, null, null, null);

        $this->expectException(InvalidArgumentException::class);

        self::parse(self::parser(), self::request('POST', [], '{}'), $target, self::negotiation());
    }

    public function testRefusesAMethodTheRouteDoesNotCarry(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::parse(self::parser(), self::request('POST', [], '{}'), self::resourceRoute(), self::negotiation());
    }

    private static function parser(?ResourceRegistry $resources = null): OperationParser
    {
        $resources ??= self::registry();
        $api = new ApiMetadata(
            'pets api',
            '1.0',
            'acme/v1',
            $resources,
            [],
            [],
            Samples::operators(),
            [],
            null,
            Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );

        return new OperationParser(
            $resources,
            new QueryParameterGuard([]),
            new CollectionReadingParser(
                new FilterParser($api),
                new SortParser($resources),
                new PageParser(NamingPolicy::SnakeCase),
                new CursorConditions($resources, NamingPolicy::SnakeCase),
            ),
            new PageParser(NamingPolicy::SnakeCase),
            new IncludeParser($resources),
            new FieldsetParser($resources),
            new JsonBodyDecoder(),
            new DocumentParser($resources),
            new StatusSelector(),
        );
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function request(string $method, array $query = [], string $body = ''): ServerRequest
    {
        $fields = $body === '' ? [] : ['Content-Type' => self::JSON_API];

        return new ServerRequest($method, new Headers($fields), $query, [], $body);
    }

    private static function negotiation(): Negotiation
    {
        return new Negotiation(MediaType::jsonApi());
    }

    private static function cursorNegotiation(): Negotiation
    {
        return new Negotiation(MediaType::jsonApi([], [CursorProfile::URI]), [CursorProfile::URI]);
    }

    private static function routeOf(string $route): RouteTarget
    {
        return match ($route) {
            'collection' => self::collection(),
            'resource' => self::resourceRoute(),
            'related' => self::related('orders'),
            'relationship' => self::relationship('orders'),
            default => throw new InvalidArgumentException('the tests address no route "' . $route . '"'),
        };
    }

    private static function collection(): RouteTarget
    {
        return new RouteTarget(RouteKind::Collection, 'pets', null, null);
    }

    private static function resourceRoute(): RouteTarget
    {
        return new RouteTarget(RouteKind::Resource, 'pets', '7', null);
    }

    private static function related(string $relationship): RouteTarget
    {
        return new RouteTarget(RouteKind::Related, 'pets', '7', $relationship);
    }

    private static function relationship(string $relationship): RouteTarget
    {
        return new RouteTarget(RouteKind::Relationship, 'pets', '7', $relationship);
    }

    /**
     * @param string $comparison `@gt` or `@lt`, by the direction of the field and the cursor
     * @param string $byId       the comparison on `id`, which sorts ascending
     */
    private static function chain(string $field, mixed $value, string $comparison, string $id, string $byId): FilterNode
    {
        $path = new FieldPath([$field]);

        return new AnyOf([
            new Condition($path, $comparison, $value),
            new AllOf([
                new Condition($path, '@eq', $value),
                new Condition(new FieldPath(['id']), $byId, $id),
            ]),
        ]);
    }

    private static function filterOf(Operation $operation): ?FilterNode
    {
        self::assertTrue(
            $operation instanceof FetchRelated || $operation instanceof FetchRelationship,
            'the request was read as another operation',
        );

        /** @var FetchRelated|FetchRelationship $operation */
        return $operation->filter;
    }

    /**
     * @return list<Identifier>
     */
    private static function linkageOf(Operation $operation): array
    {
        self::assertTrue(
            $operation instanceof ReplaceRelationship
            || $operation instanceof AddToRelationship
            || $operation instanceof RemoveFromRelationship,
            'the request was read as another operation',
        );

        /** @var AddToRelationship|RemoveFromRelationship|ReplaceRelationship $operation */
        return $operation->linkage->identifiers();
    }

    /**
     * @return array{?string, ?list<string>}
     */
    private static function includeAndFieldsetOf(Operation $operation, string $type): array
    {
        self::assertTrue(
            $operation instanceof ReplaceRelationship
            || $operation instanceof AddToRelationship
            || $operation instanceof RemoveFromRelationship,
            'the request was read as another operation',
        );

        /** @var AddToRelationship|RemoveFromRelationship|ReplaceRelationship $operation */
        return [$operation->include?->toString(), $operation->fields->fields($type)];
    }

    private static function parse(
        OperationParser $parser,
        ServerRequest $request,
        RouteTarget $target,
        Negotiation $negotiation,
    ): Operation|PendingWrite {
        return $parser->parse(
            $request,
            $target,
            $negotiation,
            (new AcceptLanguageParser())->parse($request->headers->line('Accept-Language')),
        );
    }

    private static function write(OperationParser $parser, ServerRequest $request, RouteTarget $target): Operation
    {
        $write = self::parse($parser, $request, $target, self::negotiation());

        self::assertInstanceOf(PendingWrite::class, $write);

        return $parser->parseContent($request, $write);
    }

    /**
     * @return array{int, list<array{string, array<string, scalar>, ?string}>}
     */
    private static function refusal(ServerRequest $request, RouteTarget $target): array
    {
        return self::problems(
            static function (OperationParser $parser) use ($request, $target): Operation|PendingWrite {
                return self::parse($parser, $request, $target, self::negotiation());
            },
        );
    }

    /**
     * the refusal of `parse()` or, past it, of `parseContent()`
     *
     * @return array{int, list<array{string, array<string, scalar>, ?string}>}
     */
    private static function contentRefusal(ServerRequest $request, RouteTarget $target): array
    {
        return self::problems(
            static function (OperationParser $parser) use ($request, $target): Operation {
                return self::write($parser, $request, $target);
            },
        );
    }

    /**
     * @param Closure(OperationParser): (Operation|PendingWrite) $parse
     *
     * @return array{int, list<array{string, array<string, scalar>, ?string}>}
     */
    private static function problems(Closure $parse): array
    {
        try {
            $parse(self::parser());
        } catch (JsonApiException $exception) {
            $problems = [];

            foreach ($exception->descriptions() as $description) {
                $problems[] = [
                    $description->code instanceof ErrorCode ? $description->code->value : $description->code,
                    $description->parameters,
                    $description->pointer ?? $description->parameter,
                ];
            }

            return [$exception->status(), $problems];
        }

        self::fail('the parser accepted a request it refuses');
    }

    /**
     * `description` is an integer on `pets` and text on `orders`,
     * so a cursor value read against the wrong type shows
     *
     * @param ?array<value-of<OperationKind>, list<QueryParameterMetadata>> $petsQueryParameters
     */
    private static function registry(?array $petsQueryParameters = null): ResourceRegistry
    {
        $writable = new WriteRule(true, true, false);

        return new ResourceRegistry([
            Samples::resource(
                'pets',
                [
                    Samples::attribute('name', sortable: true, operators: ['@eq'], writeRule: $writable),
                    Samples::attribute('description', new IntegerCodec(), writeRule: $writable),
                ],
                [
                    Samples::toOne(
                        'owner',
                        'customers',
                        updates: [RelationshipUpdate::Replace],
                        writeRule: $writable,
                        queryParameters: [OperationKind::ReplaceRelationship->value => [self::optional('notifyOwner')]],
                    ),
                    Samples::toMany('orders', 'orders', updates: [
                        RelationshipUpdate::Replace,
                        RelationshipUpdate::Add,
                        RelationshipUpdate::Remove,
                    ], defaultSort: Sort::fromString('description'), paginationPolicy: new PaginationPolicy(
                        5,
                        25,
                    ), writeRule: $writable, queryParameters: [
                        OperationKind::FetchRelated->value => [self::optional('placedSince')],
                    ]),
                ],
                Capability::cases(),
                defaultSort: Sort::fromString('-name'),
                paginationPolicy: new PaginationPolicy(10, 50),
                maxIncludeDepth: 2,
                queryParameters: $petsQueryParameters ?? [
                    OperationKind::FetchCollection->value => [
                        self::optional('nearBy'),
                        new QueryParameterMetadata('minAge', new IntegerCodec(), false),
                    ],
                    OperationKind::FetchResource->value => [self::optional('previewMode')],
                    OperationKind::UpdateResource->value => [self::optional('notifyOwner')],
                    OperationKind::DeleteResource->value => [self::optional('hardDelete')],
                ],
            ),
            Samples::resource('customers', [Samples::attribute('name', sortable: true, writeRule: $writable)], [
                Samples::toMany('pets', 'pets', writeRule: $writable),
            ], Capability::cases(), maxIncludeDepth: 2),
            Samples::resource('orders', [Samples::attribute(
                'description',
                sortable: true,
                operators: ['@eq'],
                writeRule: $writable,
            )], [
                Samples::toOne('customer', 'customers', writeRule: $writable),
            ], Capability::cases(), maxIncludeDepth: 2),
            // `UuidCodec` lower-cases a UUID and refuses other text,
            // which shows whether a route id was decoded
            Samples::resource('documents', [Samples::attribute(
                'title',
                sortable: true,
                writeRule: $writable,
            )], capabilities: Capability::cases(), maxIncludeDepth: 2, id: new IdMetadata(
                new UuidCodec(),
                static function (object $record): string {
                    return 'id';
                },
                true,
            )),
            Samples::resource('notes', [Samples::attribute(
                'title',
                sortable: true,
                writeRule: $writable,
            )], capabilities: Capability::cases(), maxIncludeDepth: 2, id: new IdMetadata(
                new TrimmingCodec(),
                static function (object $record): string {
                    return 'id';
                },
                false,
            )),
        ]);
    }

    public function testTheDeclaredParametersOfARoutePassAndReachItsOperation(): void
    {
        $operation = self::parse(
            self::parser(),
            self::request('GET', ['nearBy' => 'park', 'minAge' => '3', 'sort' => 'name']),
            self::collection(),
            self::negotiation(),
        );

        self::assertInstanceOf(FetchCollection::class, $operation);
        self::assertSame(['nearBy', 'minAge'], $operation->queryParameters->names());
        self::assertSame('park', $operation->queryParameters->get('nearBy'));
        self::assertSame(3, $operation->queryParameters->get('minAge'));
    }

    public function testARouteThatDeclaresNoParameterCarriesNoQueryParameter(): void
    {
        $operation = self::parse(
            self::parser(),
            self::request('GET'),
            new RouteTarget(RouteKind::Collection, 'customers', null, null),
            self::negotiation(),
        );

        self::assertInstanceOf(FetchCollection::class, $operation);
        self::assertSame([], $operation->queryParameters->names());
    }

    public function testAParameterOfAnotherKindOrUndeclaredIsRefused(): void
    {
        self::assertSame(
            [400, [
                ['unsupported_query_parameter', ['name' => 'previewMode'], 'previewMode'],
                ['unsupported_query_parameter', ['name' => 'farAway'], 'farAway'],
            ]],
            self::refusal(self::request('GET', ['previewMode' => 'yes', 'farAway' => '1']), self::collection()),
        );
    }

    public function testAValueItsCodecRefusesIsRefusedAtTheParameter(): void
    {
        [$status, $problems] = self::refusal(self::request('GET', ['minAge' => 'old']), self::collection());

        self::assertSame(400, $status);
        self::assertSame('minAge', $problems[0][2]);
    }

    public function testARequiredParameterTheRequestLeavesOutIsRefused(): void
    {
        $parser = self::parser(self::registry([
            OperationKind::FetchCollection->value => [
                new QueryParameterMetadata('previewMode', new StringCodec(), true),
            ],
        ]));

        try {
            self::parse($parser, self::request('GET'), self::collection(), self::negotiation());
            self::fail('the parser accepted a request without its required parameter');
        } catch (JsonApiException $refusal) {
            self::assertSame(400, $refusal->status());
            self::assertSame(ErrorCode::MissingQueryParameter, $refusal->descriptions()[0]->code);
            self::assertSame('previewMode', $refusal->descriptions()[0]->parameter);
        }
    }

    public function testAWriteCarriesItsParametersPastTheContent(): void
    {
        $update = self::write(
            self::parser(),
            self::request('PATCH', ['notifyOwner' => 'yes'], '{"data": {"type": "pets", "id": "7"}}'),
            self::resourceRoute(),
        );
        $delete = self::parse(
            self::parser(),
            self::request('DELETE', ['hardDelete' => 'yes']),
            self::resourceRoute(),
            self::negotiation(),
        );

        self::assertInstanceOf(UpdateResource::class, $update);
        self::assertSame('yes', $update->queryParameters->get('notifyOwner'));
        self::assertInstanceOf(DeleteResource::class, $delete);
        self::assertSame('yes', $delete->queryParameters->get('hardDelete'));
    }

    public function testARelationshipRouteReadsTheParametersOfItsRelationship(): void
    {
        $related = self::parse(
            self::parser(),
            self::request('GET', ['placedSince' => '2026']),
            self::related('orders'),
            self::negotiation(),
        );
        $replaced = self::write(
            self::parser(),
            self::request('PATCH', ['notifyOwner' => 'no'], '{"data": {"type": "customers", "id": "2"}}'),
            self::relationship('owner'),
        );

        self::assertInstanceOf(FetchRelated::class, $related);
        self::assertSame('2026', $related->queryParameters->get('placedSince'));
        self::assertInstanceOf(ReplaceRelationship::class, $replaced);
        self::assertSame('no', $replaced->queryParameters->get('notifyOwner'));
        self::assertSame(
            [400, [['unsupported_query_parameter', ['name' => 'placedSince'], 'placedSince']]],
            self::refusal(self::request('GET', ['placedSince' => '2026']), self::relationship('orders')),
        );
    }

    private static function optional(string $name): QueryParameterMetadata
    {
        return new QueryParameterMetadata($name, new StringCodec(), false);
    }
}
