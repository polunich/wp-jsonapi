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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\AcceptLanguageParser;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\IdMetadata;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\WriteRule;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\AtomicOperation;
use Polunich\WpJsonApi\Operation\AtomicOperations;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Parsing\AtomicParser;
use Polunich\WpJsonApi\Parsing\DocumentParser;
use Polunich\WpJsonApi\Parsing\FieldsetParser;
use Polunich\WpJsonApi\Parsing\JsonBodyDecoder;
use Polunich\WpJsonApi\Parsing\QueryParameterGuard;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;

/**
 * @internal
 */
#[CoversClass(AtomicParser::class)]
final class AtomicParserTest extends TestCase
{
    private const JSON_API = 'application/vnd.api+json;ext="https://jsonapi.org/ext/atomic"';

    /** every type of `registry()`, as an atomic route without `types()` takes them */
    private const TYPES = ['pets', 'customers', 'orders', 'codes'];

    public function testReadsTheOperationsInTheOrderTheDocumentWritesThem(): void
    {
        $operations = self::parse(self::request('{"atomic:operations": [
            {"op": "add", "data": {"type": "pets", "attributes": {"name": "one"}}},
            {"op": "update", "data": {"type": "pets", "id": "7", "attributes": {"name": "two"}}},
            {"op": "remove", "ref": {"type": "pets", "id": "8"}},
            {"op": "update", "ref": {"type": "pets", "id": "9", "relationship": "orders"}, "data": []},
            {"op": "add", "ref": {"type": "pets", "id": "9", "relationship": "orders"},
                "data": [{"type": "orders", "id": "1"}]},
            {"op": "remove", "ref": {"type": "pets", "id": "9", "relationship": "orders"},
                "data": [{"type": "orders", "id": "1"}]}
        ]}'));

        self::assertSame([
            OperationKind::CreateResource,
            OperationKind::UpdateResource,
            OperationKind::DeleteResource,
            OperationKind::ReplaceRelationship,
            OperationKind::AddToRelationship,
            OperationKind::RemoveFromRelationship,
        ], array_map(
            static function (AtomicOperation $operation): OperationKind {
                return $operation->kind;
            },
            $operations->all(),
        ));
    }

    public function testACreateBuildsTheOperationASinglePostWouldProduce(): void
    {
        $operations = self::parse(self::request('{"atomic:operations": [{
            "op": "add",
            "data": {
                "type": "pets",
                "attributes": {"name": "Ember Hamster"},
                "relationships": {"owner": {"data": {"type": "customers", "id": "9"}}}
            }
        }]}'));

        $operation = self::build($operations->all()[0]);

        self::assertInstanceOf(CreateResource::class, $operation);
        self::assertSame('pets', $operation->type);
        self::assertSame('Ember Hamster', $operation->attributes->get('name'));
        self::assertSame('9', $operation->relationships->get('owner')->identifier()?->id);
    }

    public function testAnOperationCarriesTheFieldsetsOfTheRequestAndNoIncludePath(): void
    {
        $request = self::request(
            '{"atomic:operations": [{"op": "add", "data": {"type": "pets"}}]}',
            ['fields' => ['pets' => 'name']],
        );

        $operation = self::build(self::parse($request)->all()[0]);

        self::assertInstanceOf(CreateResource::class, $operation);
        self::assertSame(['name'], $operation->fields->fields('pets'));
        self::assertNull($operation->include);
    }

    public function testAWriteOfAnAtomicRequestCarriesNoPrecondition(): void
    {
        $operations = self::parse(self::request(
            '{"atomic:operations": [{"op": "remove", "ref": {"type": "pets", "id": "8"}}]}',
        ));

        $operation = self::build($operations->all()[0]);

        self::assertInstanceOf(DeleteResource::class, $operation);
        self::assertNull($operation->precondition);
    }

    public function testTheIdOfARefIsReadByTheIdCodecOfItsType(): void
    {
        $operations = self::parse(self::request(
            '{"atomic:operations": [{"op": "remove", "ref":'
            . ' {"type": "codes", "id": "AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE"}}]}',
        ));

        $operation = self::build($operations->all()[0]);

        self::assertInstanceOf(DeleteResource::class, $operation);
        self::assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $operation->target->id);
    }

    public function testARefIdTheCodecRefusesIsNotFoundAtThePointerOfTheMember(): void
    {
        self::assertSame(
            [404, [['not_found', [], '/atomic:operations/0/ref/id']]],
            self::refusal('{"atomic:operations": [{"op": "remove", "ref": {"type": "codes", "id": "not-a-uuid"}}]}'),
        );
    }

    public function testARefIdTheCodecRefusesBesideAnotherProblemIsTheStatusTheSelectorMerges(): void
    {
        self::assertSame(
            [400, [
                ['not_found', [], '/atomic:operations/0/ref/id'],
                ['bad_request', [], '/atomic:operations/1/op'],
            ]],
            self::refusal('{"atomic:operations": [
                {"op": "remove", "ref": {"type": "codes", "id": "not-a-uuid"}},
                {"op": "rewrite", "ref": {"type": "codes", "id": "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee"}}
            ]}'),
        );
    }

    public function testTheLidOfARefPassesNoCodec(): void
    {
        $operations = self::parse(self::request('{"atomic:operations": [
            {"op": "add", "data": {"type": "codes", "lid": "fresh", "attributes": {"label": "a"}}},
            {"op": "remove", "ref": {"type": "codes", "lid": "fresh"}}
        ]}'));

        $operation = self::build($operations->all()[1]);

        self::assertInstanceOf(DeleteResource::class, $operation);
        self::assertSame('101', $operation->target->id);
    }

    public function testAnUpdateWithoutRefTargetsTheResourceItsDataNames(): void
    {
        $operations = self::parse(self::request('{"atomic:operations": [{
            "op": "update",
            "data": {"type": "pets", "id": "13", "attributes": {"name": "Rex"}}
        }]}'));

        $operation = self::build($operations->all()[0]);

        self::assertInstanceOf(UpdateResource::class, $operation);
        self::assertSame('13', $operation->target->id);
        self::assertSame('Rex', $operation->attributes->get('name'));
    }

    public function testAnUpdateWhoseDataNamesAnotherResourceThanItsRefIsRefused(): void
    {
        $document = '{"atomic:operations": [{
            "op": "update",
            "ref": {"type": "pets", "id": "7"},
            "data": {"type": "pets", "id": "8", "attributes": {"name": "one"}}
        }]}';

        self::assertSame(
            [409, [['id_mismatch', ['id' => '8', 'target' => '7'], '/atomic:operations/0/data/id']]],
            self::refusal($document),
        );
    }

    /**
     * @param class-string<object> $expected
     */
    #[DataProvider('relationshipWrites')]
    public function testARefWithARelationshipNamesTheRelationshipWrite(
        string $document,
        string $expected,
        int $members,
    ): void {
        $operation = self::build(self::parse(self::request($document))->all()[0]);

        self::assertInstanceOf($expected, $operation);
        self::assertSame('orders', $operation->relationship());
        self::assertCount($members, self::linkageOf($operation));
    }

    /**
     * @return array<string, array{string, class-string<object>, int}>
     */
    public static function relationshipWrites(): array
    {
        return [
            '"update" replaces all the members' => [
                '{"atomic:operations": [{"op": "update", "ref": {"type": "pets", "id": "1", "relationship": "orders"},'
                . ' "data": [{"type": "orders", "id": "2"}, {"type": "orders", "id": "3"}]}]}',
                ReplaceRelationship::class,
                2,
            ],
            '"add" adds members' => [
                '{"atomic:operations": [{"op": "add", "ref": {"type": "pets", "id": "1", "relationship": "orders"},'
                . ' "data": [{"type": "orders", "id": "2"}]}]}',
                AddToRelationship::class,
                1,
            ],
            '"remove" removes members' => [
                '{"atomic:operations": [{"op": "remove", "ref": {"type": "pets", "id": "1", "relationship": "orders"},'
                . ' "data": [{"type": "orders", "id": "2"}]}]}',
                RemoveFromRelationship::class,
                1,
            ],
        ];
    }

    public function testALaterOperationTargetsAResourceByTheLidACreateDeclared(): void
    {
        $operations = self::parse(self::request('{"atomic:operations": [
            {"op": "add", "data": {"type": "pets", "lid": "draft", "attributes": {"name": "one"}}},
            {"op": "update", "ref": {"type": "pets", "lid": "draft"},
                "data": {"type": "pets", "lid": "draft", "attributes": {"name": "two"}}}
        ]}'));

        self::assertSame('draft', $operations->all()[0]->lid);

        $operation = self::build($operations->all()[1]);

        self::assertInstanceOf(UpdateResource::class, $operation);
        self::assertSame('101', $operation->target->id);
    }

    public function testALinkageIdentifierNamesALidOfAPriorOperation(): void
    {
        $operations = self::parse(self::request('{"atomic:operations": [
            {"op": "add", "data": {"type": "customers", "lid": "seller", "attributes": {"name": "Ada"}}},
            {"op": "add", "data": {"type": "pets", "attributes": {"name": "one"},
                "relationships": {"owner": {"data": {"type": "customers", "lid": "seller"}}}}}
        ]}'));

        $operation = self::build($operations->all()[1]);

        self::assertInstanceOf(CreateResource::class, $operation);
        self::assertSame('101', $operation->relationships->get('owner')->identifier()?->id);
    }

    public function testWritesAbsolutePointersIntoTheOperationThatCarriesTheProblem(): void
    {
        $document = '{"atomic:operations": [
            {"op": "add", "data": {"type": "pets", "attributes": {"name": "one"}}},
            {"op": "add", "data": {"type": "pets", "attributes": {"nowhere": "two"}}}
        ]}';

        self::assertSame(
            [
                422,
                [[
                    'unknown_field',
                    ['type' => 'pets', 'field' => 'nowhere'],
                    '/atomic:operations/1/data/attributes/nowhere',
                ]],
            ],
            self::refusal($document),
        );
    }

    public function testRefusesALidDeclaredTwiceForOneType(): void
    {
        $document = '{"atomic:operations": [
            {"op": "add", "data": {"type": "pets", "lid": "draft"}},
            {"op": "add", "data": {"type": "pets", "lid": "draft"}}
        ]}';

        self::assertSame(
            [400, [['duplicate_lid', ['lid' => 'draft', 'type' => 'pets'], '/atomic:operations/1/data/lid']]],
            self::refusal($document),
        );
    }

    public function testRefusesARefThatNamesALidNoPriorOperationDeclared(): void
    {
        $document = '{"atomic:operations": [{"op": "remove", "ref": {"type": "pets", "lid": "draft"}}]}';

        self::assertSame(
            [400, [['unknown_lid', ['lid' => 'draft', 'type' => 'pets'], '/atomic:operations/0/ref/lid']]],
            self::refusal($document),
        );
    }

    #[DataProvider('undeclaredTypes')]
    public function testAnOperationOnAnUndeclaredTypeIsRefusedWithConflict(string $document, string $pointer): void
    {
        self::assertSame(
            [409, [['type_mismatch', ['type' => 'nowhere'], $pointer]]],
            self::refusal($document),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function undeclaredTypes(): array
    {
        return [
            'the data of a create' => [
                '{"atomic:operations": [{"op": "add", "data": {"type": "nowhere"}}]}',
                '/atomic:operations/0/data/type',
            ],
            'the ref of a delete' => [
                '{"atomic:operations": [{"op": "remove", "ref": {"type": "nowhere", "id": "1"}}]}',
                '/atomic:operations/0/ref/type',
            ],
            'the data of an update without ref' => [
                '{"atomic:operations": [{"op": "update", "data": {"type": "nowhere", "id": "1"}}]}',
                '/atomic:operations/0/data/type',
            ],
        ];
    }

    #[DataProvider('typesOutsideTheRoute')]
    public function testAnOperationOnATypeTheRouteDoesNotTakeIsRefusedWithConflict(
        string $document,
        string $pointer,
    ): void {
        self::assertSame(
            [409, [['type_mismatch', ['type' => 'customers'], $pointer]]],
            self::refusal($document, ['pets']),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function typesOutsideTheRoute(): array
    {
        return [
            'the data of a create' => [
                '{"atomic:operations": [{"op": "add", "data": {"type": "customers"}}]}',
                '/atomic:operations/0/data/type',
            ],
            'the ref of a delete' => [
                '{"atomic:operations": [{"op": "remove", "ref": {"type": "customers", "id": "1"}}]}',
                '/atomic:operations/0/ref/type',
            ],
            'the ref of an update' => [
                '{"atomic:operations": [{"op": "update", "ref": {"type": "customers", "id": "1"},'
                . ' "data": {"type": "customers", "id": "1"}}]}',
                '/atomic:operations/0/ref/type',
            ],
            'the data of an update without ref' => [
                '{"atomic:operations": [{"op": "update", "data": {"type": "customers", "id": "1"}}]}',
                '/atomic:operations/0/data/type',
            ],
            'the ref of a relationship write' => [
                '{"atomic:operations": [{"op": "update", "ref": {"type": "customers", "id": "1",'
                . ' "relationship": "pets"}, "data": []}]}',
                '/atomic:operations/0/ref/type',
            ],
        ];
    }

    /**
     * the route limits the targets alone: a relationship of its type points at any type
     */
    public function testALinkageOfARouteOfOneTypeNamesAnotherType(): void
    {
        $document = '{"atomic:operations": [{"op": "update", "ref": {"type": "pets", "id": "1",'
            . ' "relationship": "owner"}, "data": {"type": "customers", "id": "2"}}]}';

        $operation = self::build(self::parse(self::request($document), ['pets'])->all()[0]);

        self::assertInstanceOf(ReplaceRelationship::class, $operation);
        self::assertSame('pets', $operation->type());
        self::assertSame([['customers', '2']], array_map(static function (Identifier $identifier): array {
            return [$identifier->type, $identifier->id];
        }, self::linkageOf($operation)));
    }

    /**
     * a lid is declared by a create, which the route refuses for another type,
     * so a lid of that type is never declared; the 409 and the 400 merge into 400
     */
    public function testALidOfATypeOutsideTheRouteIsNeverDeclared(): void
    {
        $document = '{"atomic:operations": ['
            . '{"op": "add", "data": {"type": "customers", "lid": "c"}},'
            . ' {"op": "add", "data": {"type": "pets", "attributes": {"name": "a"}, "relationships": {"owner":'
            . ' {"data": {"type": "customers", "lid": "c"}}}}}]}';

        self::assertSame(
            [400, [
                ['type_mismatch', ['type' => 'customers'], '/atomic:operations/0/data/type'],
                [
                    'unknown_lid',
                    ['lid' => 'c', 'type' => 'customers'],
                    '/atomic:operations/1/data/relationships/owner/data/lid',
                ],
            ]],
            self::refusal($document, ['pets']),
        );
    }

    public function testAnOperationCarriesNoQueryParameter(): void
    {
        $request = self::request(
            '{"atomic:operations": [{"op": "remove", "ref": {"type": "pets", "id": "8"}}]}',
            ['fields' => ['pets' => 'name']],
        );
        $parser = self::parser(self::registry([
            OperationKind::DeleteResource->value => [new QueryParameterMetadata(
                'hardDelete',
                new StringCodec(),
                false,
            )],
        ]));

        $operation = self::build($parser->parse(
            $request,
            $parser->checkQuery($request),
            new LanguagePreferences([]),
            self::TYPES,
        )->all()[0]);

        self::assertInstanceOf(DeleteResource::class, $operation);
        self::assertSame([], $operation->queryParameters->names());
    }

    public function testAnOperationWhoseRouteRequiresAParameterIsRefusedAtItsPosition(): void
    {
        $request = self::request('{"atomic:operations": ['
            . '{"op": "add", "data": {"type": "customers", "attributes": {"name": "a"}}},'
            . ' {"op": "remove", "ref": {"type": "pets", "id": "8"}}]}');
        $parser = self::parser(self::registry([
            OperationKind::DeleteResource->value => [new QueryParameterMetadata('hardDelete', new StringCodec(), true)],
        ]));

        try {
            $parser->parse($request, $parser->checkQuery($request), new LanguagePreferences([]), self::TYPES);
            self::fail('the parser accepted an operation whose route requires a parameter');
        } catch (JsonApiException $refusal) {
            self::assertSame(400, $refusal->status());
            self::assertCount(1, $refusal->descriptions());
            self::assertSame(ErrorCode::MissingQueryParameter, $refusal->descriptions()[0]->code);
            self::assertSame(['name' => 'hardDelete'], $refusal->descriptions()[0]->parameters);
            self::assertSame('/atomic:operations/1', $refusal->descriptions()[0]->pointer);
        }
    }

    public function testARefThatNamesAnUndeclaredRelationshipIsRefused(): void
    {
        $document = '{"atomic:operations": [{"op": "add", "ref": {"type": "pets", "id": "1", "relationship":'
            . ' "nowhere"}, "data": []}]}';

        self::assertSame(
            [403, [[
                'relationship_update_not_supported',
                ['relationship' => 'nowhere', 'type' => 'pets', 'operation' => 'add_to_relationship'],
                '/atomic:operations/0/ref/relationship',
            ]]],
            self::refusal($document),
        );
    }

    public function testRefusesAnOperationThatTargetsItsResourceWithHref(): void
    {
        $document = '{"atomic:operations": [{"op": "add", "href": "/pets", "data": {"type": "pets"}}]}';

        self::assertSame(
            [400, [['bad_request', [], '/atomic:operations/0/href']]],
            self::refusal($document),
        );
    }

    #[DataProvider('malformedDocuments')]
    public function testRefusesADocumentOrAnOperationItCannotRead(string $document, string $pointer): void
    {
        self::assertSame([400, [['bad_request', [], $pointer]]], self::refusal($document));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedDocuments(): array
    {
        return [
            'a body that is no object' => ['[]', ''],
            'a document without atomic:operations' => ['{"data": null}', ''],
            'atomic:operations that is no array' => ['{"atomic:operations": {}}', '/atomic:operations'],
            'no operation at all, the array holding "one or more"' => [
                '{"atomic:operations": []}',
                '/atomic:operations',
            ],
            'an operation object that is no object' => ['{"atomic:operations": [1]}', '/atomic:operations/0'],
            'an operation without op' => [
                '{"atomic:operations": [{"data": {"type": "pets"}}]}',
                '/atomic:operations/0',
            ],
            'an op code no operation carries' => [
                '{"atomic:operations": [{"op": "patch", "data": {"type": "pets"}}]}',
                '/atomic:operations/0/op',
            ],
            'a remove without ref, which MUST target its resource' => [
                '{"atomic:operations": [{"op": "remove"}]}',
                '/atomic:operations/0',
            ],
            'an add with a ref that names no relationship, which no create has' => [
                '{"atomic:operations": [{"op": "add", "ref": {"type": "pets", "id": "1"}, "data": {"type": "pets"}}]}',
                '/atomic:operations/0/ref',
            ],
            'a ref that names neither id nor lid' => [
                '{"atomic:operations": [{"op": "remove", "ref": {"type": "pets"}}]}',
                '/atomic:operations/0/ref',
            ],
            'a ref whose id is the empty string, which identifies no resource' => [
                '{"atomic:operations": [{"op": "remove", "ref": {"type": "pets", "id": ""}}]}',
                '/atomic:operations/0/ref/id',
            ],
            'a ref whose lid is the empty string' => [
                '{"atomic:operations": [{"op": "remove", "ref": {"type": "pets", "lid": ""}}]}',
                '/atomic:operations/0/ref/lid',
            ],
            'a ref that names both id and lid' => [
                '{"atomic:operations": [{"op": "remove", "ref": {"type": "pets", "id": "1", "lid": "draft"}}]}',
                '/atomic:operations/0/ref',
            ],
            'a ref whose relationship is no member name' => [
                '{"atomic:operations": [{"op": "add", "ref": {"type": "pets", "id": "1", "relationship": 5},'
                . ' "data": []}]}',
                '/atomic:operations/0/ref/relationship',
            ],
        ];
    }

    public function testCollectsTheProblemsOfEveryOperation(): void
    {
        $document = '{"atomic:operations": [
            {"op": "patch", "data": {"type": "pets"}},
            {"op": "remove", "ref": {"type": "nowhere", "id": "1"}}
        ]}';

        self::assertSame(
            [400, [
                ['bad_request', [], '/atomic:operations/0/op'],
                ['type_mismatch', ['type' => 'nowhere'], '/atomic:operations/1/ref/type'],
            ]],
            self::refusal($document),
        );
    }

    /**
     * the body is no JSON text, so any read of the document would fail the test
     */
    public function testCheckQueryReturnsTheQueryPhaseAloneAndReadsNoDocument(): void
    {
        $fields = self::parser()->checkQuery(self::request('not json', ['fields' => ['pets' => 'name']]));

        self::assertSame(['name'], $fields->fields('pets'));
    }

    public function testCheckQueryRefusesAQueryParameterOfTheRoute(): void
    {
        $request = self::request('not json', ['include' => 'owner']);

        try {
            self::parser()->checkQuery($request);
        } catch (JsonApiException $exception) {
            self::assertSame(400, $exception->status());
            self::assertSame(
                ErrorCode::UnsupportedQueryParameter,
                $exception->descriptions()[0]->code,
            );

            return;
        }

        self::fail('the parser accepted a request it refuses');
    }

    public function testEveryOperationCarriesTheFieldsetsTheQueryPhaseReturned(): void
    {
        $request = self::request(
            '{"atomic:operations": [{"op": "add", "data": {"type": "pets", "attributes": {"name": "a"}}}]}',
            ['fields' => ['pets' => 'name']],
        );
        $parser = self::parser();

        $operation = self::build($parser->parse(
            $request,
            $parser->checkQuery($request),
            new LanguagePreferences([]),
            self::TYPES,
        )->all()[0]);

        self::assertInstanceOf(CreateResource::class, $operation);
        self::assertSame(['name'], $operation->fields->fields('pets'));
    }

    public function testABodyThatIsNoJsonTextIsRefusedWithInvalidJson(): void
    {
        self::assertSame([400, [['invalid_json', [], null]]], self::refusal('{'));
    }

    public function testEveryOperationCarriesTheLanguagesOfTheRequest(): void
    {
        $request = new ServerRequest(
            'POST',
            new Headers(['Content-Type' => self::JSON_API, 'Accept-Language' => 'uk']),
            [],
            [],
            '{"atomic:operations": [{"op": "remove", "ref": {"type": "pets", "id": "8"}}]}',
        );

        $operation = self::build(self::parse($request)->all()[0]);

        self::assertInstanceOf(DeleteResource::class, $operation);
        self::assertSame('uk', $operation->languages->select(['en', 'uk']));
    }

    /**
     * @param non-empty-list<string> $types the types of the route
     */
    private static function parse(ServerRequest $request, array $types = self::TYPES): AtomicOperations
    {
        $parser = self::parser();

        return $parser->parse(
            $request,
            $parser->checkQuery($request),
            (new AcceptLanguageParser())->parse($request->headers->line('Accept-Language')),
            $types,
        );
    }

    private static function parser(?ResourceRegistry $resources = null): AtomicParser
    {
        $resources ??= self::registry();

        return new AtomicParser(
            $resources,
            new QueryParameterGuard([]),
            new FieldsetParser($resources),
            new JsonBodyDecoder(),
            new DocumentParser($resources),
            new StatusSelector(),
        );
    }

    private static function build(AtomicOperation $operation): Operation
    {
        return $operation->build(static function (string $type, string $lid): string {
            return '101';
        });
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function request(string $body, array $query = []): ServerRequest
    {
        return new ServerRequest('POST', new Headers(['Content-Type' => self::JSON_API]), $query, [], $body);
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
            'the operation was read as another kind',
        );

        /** @var AddToRelationship|RemoveFromRelationship|ReplaceRelationship $operation */
        return $operation->linkage->identifiers();
    }

    /**
     * @param non-empty-list<string> $types the types of the route
     *
     * @return array{int, list<array{string, array<string, scalar>, ?string}>}
     */
    private static function refusal(string $document, array $types = self::TYPES): array
    {
        try {
            self::parse(self::request($document), $types);
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

        self::fail('the parser accepted a document it refuses');
    }

    /**
     * @param array<value-of<OperationKind>, list<QueryParameterMetadata>> $petsQueryParameters
     */
    private static function registry(array $petsQueryParameters = []): ResourceRegistry
    {
        $writable = new WriteRule(true, true, false);

        return new ResourceRegistry([
            Samples::resource(
                'pets',
                [Samples::attribute('name', writeRule: $writable)],
                [
                    Samples::toOne('owner', 'customers', updates: [RelationshipUpdate::Replace], writeRule: $writable),
                    Samples::toMany('orders', 'orders', updates: [
                        RelationshipUpdate::Replace,
                        RelationshipUpdate::Add,
                        RelationshipUpdate::Remove,
                    ], writeRule: $writable),
                ],
                Capability::cases(),
                queryParameters: $petsQueryParameters,
            ),
            Samples::resource(
                'customers',
                [Samples::attribute('name', writeRule: $writable)],
                capabilities: Capability::cases(),
            ),
            Samples::resource(
                'orders',
                [Samples::attribute('address', writeRule: $writable)],
                capabilities: Capability::cases(),
            ),
            // `UuidCodec` lower-cases a UUID and refuses any other text,
            // which tells a decoded ref id from one passed through
            Samples::resource('codes', [Samples::attribute(
                'label',
                writeRule: $writable,
            )], capabilities: Capability::cases(), id: new IdMetadata(
                new UuidCodec(),
                static function (object $record): string {
                    return 'id';
                },
                false,
            )),
        ]);
    }
}
