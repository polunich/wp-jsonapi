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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use Polunich\WpJsonApi\Metadata\IdMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\WriteRule;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Parsing\DocumentParser;
use Polunich\WpJsonApi\Parsing\LocalLinkage;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\TrimmingCodec;
use Polunich\WpJsonApi\Tests\Fixtures\Endpoint\EndpointSamples;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\DescribedCodec;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;

/**
 * @internal
 */
#[CoversClass(DocumentParser::class)]
final class DocumentParserTest extends TestCase
{
    public function testACreateCarriesTheTypeAndTheDecodedFieldsOfItsResourceObject(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(self::document('{
            "data": {
                "type": "photos",
                "attributes": {"title": "Ember Hamster", "width": 320},
                "relationships": {
                    "photographer": {"data": {"type": "customers", "id": "9"}},
                    "tags": {"data": [{"type": "tags", "id": "2"}, {"type": "tags", "id": "3"}]}
                }
            }
        }'), Pointer::root(), ['photos'], $errors);

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertSame('photos', $body->type);
        self::assertNull($body->id);
        self::assertNull($body->lid);
        self::assertSame(['title', 'width'], $body->attributes->names());
        self::assertSame('Ember Hamster', $body->attributes->get('title'));
        self::assertSame(320, $body->attributes->get('width'));

        $relationships = $body->relationshipsWithIds();

        self::assertSame(['photographer', 'tags'], $relationships->names());
        self::assertEquals(Linkage::toOne(new Identifier('customers', '9')), $relationships->get('photographer'));
        self::assertEquals(
            Linkage::toMany([new Identifier('tags', '2'), new Identifier('tags', '3')]),
            $relationships->get('tags'),
        );
    }

    public function testACreateThatCarriesNoFieldCarriesNoValue(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "photos"}}'),
            Pointer::root(),
            ['photos'],
            $errors,
        );

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertSame([], $body->attributes->names());
        self::assertSame([], $body->relationships->names());
    }

    public function testACreateOfSeveralTypesReadsTheTypeOfItsResourceObject(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "customers"}}'),
            Pointer::root(),
            ['photos', 'customers'],
            $errors,
        );

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertSame('customers', $body->type);
    }

    /**
     * @param list<string> $types the types the place accepts:
     *                            an endpoint one, an atomic route several
     */
    #[DataProvider('typesTheEndpointRefuses')]
    public function testACreateRefusesATypeTheEndpointDoesNotCreate(
        string $document,
        array $types,
        string $type,
    ): void {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(self::document($document), Pointer::root(), $types, $errors);

        self::assertNull($body);
        self::assertSame([409, [['type_mismatch', ['type' => $type], '/data/type']]], self::refusals($errors));
    }

    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function typesTheEndpointRefuses(): array
    {
        return [
            'another declared type' => ['{"data": {"type": "customers"}}', ['photos'], 'customers'],
            'a type that is not declared' => ['{"data": {"type": "posters"}}', ['photos'], 'posters'],
            'a declared type outside several' => [
                '{"data": {"type": "customers"}}',
                ['photos', 'imports'],
                'customers',
            ],
            'an undeclared type the place names' => ['{"data": {"type": "posters"}}', ['photos', 'posters'], 'posters'],
        ];
    }

    #[DataProvider('malformedResourceDocuments')]
    public function testACreateRefusesABodyThatIsNoResourceObject(string $document, string $pointer): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(self::document($document), Pointer::root(), ['photos'], $errors);

        self::assertNull($body);
        self::assertSame([400, [['bad_request', [], $pointer]]], self::refusals($errors));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedResourceDocuments(): array
    {
        return [
            'no JSON object at the root' => ['[]', ''],
            'no primary data' => ['{"meta": {"draft": true}}', ''],
            'primary data that is no resource object' => ['{"data": []}', '/data'],
            'primary data that is null' => ['{"data": null}', '/data'],
            'a resource object without a type' => ['{"data": {"attributes": {}}}', '/data'],
            'a type that is no string' => ['{"data": {"type": 13}}', '/data/type'],
        ];
    }

    public function testACreateRefusesAClientIdWhereTheTypeAcceptsNone(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "photos", "id": "13"}}'),
            Pointer::root(),
            ['photos'],
            $errors,
        );

        self::assertNull($body);
        self::assertSame(
            [403, [['client_id_not_supported', ['type' => 'photos'], '/data/id']]],
            self::refusals($errors),
        );
    }

    public function testACreateCarriesAClientIdTheTypeAccepts(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "imports", "id": "batch-7"}}'),
            Pointer::root(),
            ['imports'],
            $errors,
        );

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertSame('batch-7', $body->id);
    }

    public function testACreateRefusesAClientIdTheIdCodecRefuses(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "codes", "id": "not-a-uuid"}}'),
            Pointer::root(),
            ['codes'],
            $errors,
        );

        self::assertNull($body);
        self::assertSame([422, [['invalid_format', ['format' => 'uuid'], '/data/id']]], self::refusals($errors));
    }

    public function testACreateRefusesAnEmptyClientId(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "imports", "id": ""}}'),
            Pointer::root(),
            ['imports'],
            $errors,
        );

        self::assertNull($body);
        self::assertSame([422, [['unprocessable_content', [], '/data/id']]], self::refusals($errors));
    }

    public function testACreateWithoutTheClientIdItsTypeRequiresIsRefused(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "codes"}}'),
            Pointer::root(),
            ['codes'],
            $errors,
        );

        self::assertNull($body);
        self::assertSame(
            [422, [['missing_field', ['type' => 'codes', 'field' => 'id'], '/data']]],
            self::refusals($errors),
        );
    }

    public function testACreateCarriesTheLidOfItsResourceObject(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "photos", "lid": "fresh"}}'),
            Pointer::root(),
            ['photos'],
            $errors,
        );

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertNull($body->id);
        self::assertSame('fresh', $body->lid);
    }

    public function testACreateRefusesAResourceObjectWithAnIdAndALid(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "imports", "id": "batch-7", "lid": "fresh"}}'),
            Pointer::root(),
            ['imports'],
            $errors,
        );

        self::assertNull($body);
        self::assertSame(
            [400, [['lid_with_id', ['id' => 'batch-7', 'lid' => 'fresh'], '/data/lid']]],
            self::refusals($errors),
        );
    }

    /**
     * @param array{string, array<string, scalar>, ?string} $problem
     */
    #[DataProvider('impossibleLids')]
    public function testACreateRefusesALidThatNamesNoResource(string $document, int $status, array $problem): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(self::document($document), Pointer::root(), ['photos'], $errors);

        self::assertNull($body);
        self::assertSame([$status, [$problem]], self::refusals($errors));
    }

    /**
     * @return array<string, array{string, int, array{string, array<string, scalar>, ?string}}>
     */
    public static function impossibleLids(): array
    {
        return [
            'no string' => ['{"data": {"type": "photos", "lid": 13}}', 400, ['bad_request', [], '/data/lid']],
            'the empty string' => [
                '{"data": {"type": "photos", "lid": ""}}',
                422,
                ['unprocessable_content', [], '/data/lid'],
            ],
        ];
    }

    public function testALinkageRefusesTheLidOfTheResourceObjectThatDeclaresIt(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(self::document('{
            "data": {
                "type": "photos",
                "lid": "fresh",
                "relationships": {"cover": {"data": {"type": "photos", "lid": "fresh"}}}
            }
        }'), Pointer::root(), ['photos'], $errors);

        self::assertNull($body);
        self::assertSame(
            [403, [['self_referencing_lid', ['lid' => 'fresh'], '/data/relationships/cover/data/lid']]],
            self::refusals($errors),
        );
    }

    public function testALinkageRefusesALidNoOperationDeclared(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(self::document('{
            "data": {
                "type": "photos",
                "relationships": {"photographer": {"data": {"type": "customers", "lid": "owner"}}}
            }
        }'), Pointer::root(), ['photos'], $errors);

        self::assertNull($body);
        self::assertSame(
            [
                400,
                [[
                    'unknown_lid',
                    ['lid' => 'owner', 'type' => 'customers'],
                    '/data/relationships/photographer/data/lid',
                ]],
            ],
            self::refusals($errors),
        );
    }

    public function testALinkageCarriesALidAPriorOperationDeclared(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(self::document('{
            "data": {
                "type": "photos",
                "relationships": {"photographer": {"data": {"type": "customers", "lid": "owner"}}}
            }
        }'), Pointer::root(), ['photos'], $errors, ['customers' => ['owner' => true]]);

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertEquals(
            Linkage::toOne(new Identifier('customers', '9')),
            $body->relationshipsWithIds(static function (string $type, string $lid): string {
                return '9';
            })->get('photographer'),
        );
    }

    /**
     * @param array{string, array<string, scalar>, ?string} $problem
     */
    #[DataProvider('impossibleIdentifiers')]
    public function testALinkageRefusesAnIdentifierTheRelationshipDoesNotAccept(
        string $linkage,
        int $status,
        array $problem,
    ): void {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "photos", "relationships": {"photographer":'
                . ' {"data": ' . $linkage . '}}}}'),
            Pointer::root(),
            ['photos'],
            $errors,
        );

        self::assertNull($body);
        self::assertSame([$status, [$problem]], self::refusals($errors));
    }

    /**
     * @return array<string, array{string, int, array{string, array<string, scalar>, ?string}}>
     */
    public static function impossibleIdentifiers(): array
    {
        $pointer = '/data/relationships/photographer/data';

        return [
            'no object' => ['"9"', 400, ['bad_request', [], $pointer]],
            'an array where a to-one relationship writes one identifier' => [
                '[{"type": "customers", "id": "9"}]',
                400,
                ['bad_request', [], $pointer],
            ],
            'no type' => ['{"id": "9"}', 400, ['bad_request', [], $pointer]],
            'a type that is no string' => ['{"type": 9, "id": "9"}', 400, ['bad_request', [], $pointer . '/type']],
            'a type the relationship does not accept' => [
                '{"type": "tags", "id": "9"}',
                409,
                ['type_mismatch', ['type' => 'tags'], $pointer . '/type'],
            ],
            'neither an id nor a lid' => ['{"type": "customers"}', 400, ['bad_request', [], $pointer]],
            'an id and a lid' => [
                '{"type": "customers", "id": "9", "lid": "owner"}',
                400,
                ['lid_with_id', ['id' => '9', 'lid' => 'owner'], $pointer . '/lid'],
            ],
            'an empty id' => ['{"type": "customers", "id": ""}', 422, ['unprocessable_content', [], $pointer . '/id']],
        ];
    }

    public function testACreateRefusesAFieldTheTypeDoesNotDeclare(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(self::document('{
            "data": {
                "type": "photos",
                "attributes": {"caption": "a"},
                "relationships": {"breeder": {"data": null}}
            }
        }'), Pointer::root(), ['photos'], $errors);

        self::assertNull($body);
        self::assertSame([422, [
            ['unknown_field', ['type' => 'photos', 'field' => 'caption'], '/data/attributes/caption'],
            ['unknown_field', ['type' => 'photos', 'field' => 'breeder'], '/data/relationships/breeder'],
        ]], self::refusals($errors));
    }

    #[DataProvider('fieldsTheRequestMayNotWrite')]
    public function testARequestRefusesAFieldItMayNotWrite(
        bool $creating,
        string $document,
        string $field,
        string $pointer,
    ): void {
        $errors = self::errors();
        $parser = self::parser();

        $body = $creating
            ? $parser->parseCreateResource(self::document($document), Pointer::root(), ['photos'], $errors)
            : $parser->parseUpdateResource(self::document($document), Pointer::root(), 'photos', '13', null, $errors);

        self::assertNull($body);
        self::assertSame([422, [
            ['read_only_field', ['type' => 'photos', 'field' => $field], $pointer],
        ]], self::refusals($errors));
    }

    /**
     * @return array<string, array{bool, string, string, string}>
     */
    public static function fieldsTheRequestMayNotWrite(): array
    {
        return [
            'a create of an attribute no client writes' => [
                true,
                '{"data": {"type": "photos", "attributes": {"checksum": "a"}}}',
                'checksum',
                '/data/attributes/checksum',
            ],
            'an update of an attribute no client writes' => [
                false,
                '{"data": {"type": "photos", "id": "13", "attributes": {"checksum": "a"}}}',
                'checksum',
                '/data/attributes/checksum',
            ],
            'an update of an attribute a create writes' => [
                false,
                '{"data": {"type": "photos", "id": "13", "attributes": {"src": "a"}}}',
                'src',
                '/data/attributes/src',
            ],
            'a create of a relationship an update alone writes' => [
                true,
                '{"data": {"type": "photos", "relationships": {"groomer": {"data": null}}}}',
                'groomer',
                '/data/relationships/groomer',
            ],
            'an update of a relationship a create alone writes' => [
                false,
                '{"data": {"type": "photos", "id": "13", "relationships": {"uploader": {"data": null}}}}',
                'uploader',
                '/data/relationships/uploader',
            ],
        ];
    }

    public function testACreateWithoutAFieldItsTypeRequiresIsRefused(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "pets", "relationships": {}}}'),
            Pointer::root(),
            ['pets'],
            $errors,
        );

        self::assertNull($body);
        self::assertSame([422, [
            ['missing_field', ['type' => 'pets', 'field' => 'name'], '/data'],
            ['missing_field', ['type' => 'pets', 'field' => 'owner'], '/data/relationships'],
        ]], self::refusals($errors));
    }

    public function testAnUpdateRequiresNoFieldACreateRequires(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseUpdateResource(
            self::document('{"data": {"type": "pets", "id": "1"}}'),
            Pointer::root(),
            'pets',
            '1',
            null,
            $errors,
        );

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertSame([], $body->attributes->names());
    }

    public function testACreateRefusesAValueTheCodecOfItsAttributeRefuses(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "photos", "attributes": {"width": "320"}}}'),
            Pointer::root(),
            ['photos'],
            $errors,
        );

        self::assertNull($body);
        self::assertSame(
            [422, [['invalid_type', ['expected' => 'integer'], '/data/attributes/width']]],
            self::refusals($errors),
        );
    }

    public function testACreateRefusesAReadOnlyMemberOfTheValueOfAnAttribute(): void
    {
        $schedule = new ObjectCodec(
            ['version' => new IntegerCodec(), 'day' => new StringCodec()],
            ['version', 'day'],
            readOnly: ['version'],
        );
        $parser = new DocumentParser(new ResourceRegistry([Samples::resource(
            'photos',
            [Samples::attribute('schedule', $schedule, writeRule: new WriteRule(true, true, false))],
        )]));
        $errors = self::errors();

        $body = $parser->parseCreateResource(
            self::document('{"data": {"type": "photos", "attributes": {"schedule": {"version": 3, "day": "mon"}}}}'),
            Pointer::root(),
            ['photos'],
            $errors,
        );

        self::assertNull($body);
        self::assertSame(
            [422, [['read_only_member', ['member' => 'version'], '/data/attributes/schedule/version']]],
            self::refusals($errors),
        );
    }

    public function testAnAtMemberIsNoFieldAndIsIgnored(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(self::document('{
            "data": {
                "type": "photos",
                "attributes": {"@context": "https://example.com", "title": "Ember Hamster"},
                "relationships": {"@context": {"data": null}}
            }
        }'), Pointer::root(), ['photos'], $errors);

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertSame(['title'], $body->attributes->names());
        self::assertSame([], $body->relationships->names());
    }

    public function testAnUpdateCarriesTheMembersItsResourceObjectSets(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseUpdateResource(self::document('{
            "data": {
                "type": "photos",
                "id": "13",
                "attributes": {"title": "To TDD or Not"},
                "relationships": {"tags": {"data": []}}
            }
        }'), Pointer::root(), 'photos', '13', null, $errors);

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertSame('photos', $body->type);
        self::assertSame('13', $body->id);
        self::assertSame(['title'], $body->attributes->names());
        self::assertEquals(Linkage::toMany([]), $body->relationshipsWithIds()->get('tags'));
    }

    public function testAnUpdateRefusesAnIdThatIsNotTheTargets(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseUpdateResource(
            self::document('{"data": {"type": "photos", "id": "14"}}'),
            Pointer::root(),
            'photos',
            '13',
            null,
            $errors,
        );

        self::assertNull($body);
        self::assertSame(
            [409, [['id_mismatch', ['id' => '14', 'target' => '13'], '/data/id']]],
            self::refusals($errors),
        );
    }

    public function testAnUpdateWhoseDocumentIdIsAnotherSpellingOfTheTargetIsAccepted(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseUpdateResource(
            self::document('{"data": {"type": "codes", "id": "AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE"}}'),
            Pointer::root(),
            'codes',
            'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            null,
            $errors,
        );

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $body->id);
    }

    public function testAnUpdateWhoseDocumentIdNamesAnotherResourceIsConflict(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseUpdateResource(
            self::document('{"data": {"type": "codes", "id": "11111111-2222-4333-8444-555555555555"}}'),
            Pointer::root(),
            'codes',
            'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            null,
            $errors,
        );

        self::assertNull($body);
        self::assertSame(409, self::refusals($errors)[0]);
    }

    public function testAnUpdateWhoseDocumentIdTheCodecRefusesIsConflict(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseUpdateResource(
            self::document('{"data": {"type": "codes", "id": "not-a-uuid"}}'),
            Pointer::root(),
            'codes',
            'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            null,
            $errors,
        );

        self::assertNull($body);
        self::assertSame(
            [409, [[
                'id_mismatch',
                ['id' => 'not-a-uuid', 'target' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
                '/data/id',
            ]]],
            self::refusals($errors),
        );
    }

    public function testTheConflictNamesTheWrittenIdBesideTheDecodedRouteId(): void
    {
        $errors = self::errors();

        self::parser()->parseUpdateResource(
            self::document('{"data": {"type": "codes", "id": "11111111-2222-4333-8444-FFFFFFFFFFFF"}}'),
            Pointer::root(),
            'codes',
            'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            null,
            $errors,
        );

        self::assertSame(
            [409, [[
                'id_mismatch',
                [
                    'id' => '11111111-2222-4333-8444-FFFFFFFFFFFF',
                    'target' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
                ],
                '/data/id',
            ]]],
            self::refusals($errors),
        );
    }

    /**
     * the target arrives decoded, so a linkage id is decoded too: no operation mixes both spellings
     */
    public function testTheIdOfALinkageIdentifierIsReadByTheCodecOfItsRelatedType(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "stamps", "relationships": {"code": {"data":
                {"type": "codes", "id": "AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE"}
            }}}}'),
            Pointer::root(),
            ['stamps'],
            $errors,
        );

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertEquals(
            Linkage::toOne(new Identifier('codes', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')),
            $body->relationshipsWithIds()->get('code'),
        );
    }

    public function testALinkageIdentifierWhoseIdTheCodecRefusesIsUnprocessableAtThePointerOfItsId(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "stamps", "relationships": {"code": {"data":
                {"type": "codes", "id": "not-a-uuid"}
            }}}}'),
            Pointer::root(),
            ['stamps'],
            $errors,
        );

        self::assertNull($body);
        self::assertSame(
            [422, [['invalid_format', ['format' => 'uuid'], '/data/relationships/code/data/id']]],
            self::refusals($errors),
        );
    }

    public function testALinkageIdentifierWhoseIdDecodesToTheEmptyStringIsUnprocessable(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "stamps", "relationships": {"note": {"data":
                {"type": "notes", "id": "  "}
            }}}}'),
            Pointer::root(),
            ['stamps'],
            $errors,
        );

        self::assertNull($body);
        self::assertSame(
            [422, [['unprocessable_content', [], '/data/relationships/note/data/id']]],
            self::refusals($errors),
        );
    }

    public function testTheLidWithIdRefusalNamesTheIdTheClientWrote(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "stamps", "relationships": {"code": {"data":
                {"type": "codes", "id": "NOT-A-UUID", "lid": "fresh"}
            }}}}'),
            Pointer::root(),
            ['stamps'],
            $errors,
        );

        self::assertNull($body);
        self::assertSame(
            [400, [[
                'lid_with_id',
                ['id' => 'NOT-A-UUID', 'lid' => 'fresh'],
                '/data/relationships/code/data/lid',
            ]]],
            self::refusals($errors),
        );
    }

    public function testAnUpdateRefusesAResourceObjectWithoutTheMemberThatNamesIt(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseUpdateResource(
            self::document('{"data": {"type": "photos"}}'),
            Pointer::root(),
            'photos',
            '13',
            null,
            $errors,
        );

        self::assertNull($body);
        self::assertSame([400, [['bad_request', [], '/data']]], self::refusals($errors));
    }

    public function testAnUpdateTargetedByALidIsNamedByItsResourceObject(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseUpdateResource(
            self::document('{"data": {"type": "photos", "lid": "fresh", "attributes": {"title": "a"}}}'),
            Pointer::root(),
            'photos',
            null,
            'fresh',
            $errors,
        );

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertNull($body->id);
        self::assertSame('fresh', $body->lid);
        self::assertSame(['title'], $body->attributes->names());
    }

    /**
     * @param ?string                                       $id      the id of the target
     * @param ?string                                       $lid     the lid of the target
     * @param array{string, array<string, scalar>, ?string} $problem
     */
    #[DataProvider('resourceObjectsThatNameAnotherResource')]
    public function testAnUpdateRefusesAResourceObjectThatNamesAnotherResource(
        string $document,
        ?string $id,
        ?string $lid,
        int $status,
        array $problem,
    ): void {
        $errors = self::errors();

        $body = self::parser()->parseUpdateResource(
            self::document($document),
            Pointer::root(),
            'photos',
            $id,
            $lid,
            $errors,
        );

        self::assertNull($body);
        self::assertSame([$status, [$problem]], self::refusals($errors));
    }

    /**
     * @return array<string, array{
     *     string,
     *     ?string,
     *     ?string,
     *     int,
     *     array{string, array<string, scalar>, ?string},
     * }>
     */
    public static function resourceObjectsThatNameAnotherResource(): array
    {
        return [
            'another lid' => [
                '{"data": {"type": "photos", "lid": "other"}}',
                null,
                'fresh',
                409,
                ['conflict', [], '/data/lid'],
            ],
            'an id where a lid names the target' => [
                '{"data": {"type": "photos", "id": "13"}}',
                null,
                'fresh',
                400,
                ['bad_request', [], '/data'],
            ],
            'a lid beside the id' => [
                '{"data": {"type": "photos", "id": "13", "lid": "fresh"}}',
                '13',
                null,
                400,
                ['bad_request', [], '/data/lid'],
            ],
            'an id beside the lid that names the target' => [
                '{"data": {"type": "photos", "lid": "fresh", "id": "13"}}',
                null,
                'fresh',
                400,
                ['bad_request', [], '/data/lid'],
            ],
            'an id that is no string' => [
                '{"data": {"type": "photos", "id": 13}}',
                '13',
                null,
                400,
                ['bad_request', [], '/data/id'],
            ],
        ];
    }

    /**
     * a target with neither or both is a mistake of the library, not of the request
     *
     * @param ?string $id  the id of the target
     * @param ?string $lid the lid of the target
     */
    #[DataProvider('impossibleTargets')]
    public function testAnUpdateNamesItsTargetByAnIdOrByALid(?string $id, ?string $lid): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('names exactly one of the two');

        self::parser()->parseUpdateResource(
            self::document('{"data": {"type": "photos", "id": "13"}}'),
            Pointer::root(),
            'photos',
            $id,
            $lid,
            self::errors(),
        );
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function impossibleTargets(): array
    {
        return [
            'neither' => [null, null],
            'both' => ['13', 'fresh'],
        ];
    }

    public function testAnUpdateRefusesTheFullReplacementOfARelationshipThatDoesNotDeclareIt(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseUpdateResource(
            self::document('{"data": {"type": "photos", "id": "13", "relationships": {"albums": {"data": []}}}}'),
            Pointer::root(),
            'photos',
            '13',
            null,
            $errors,
        );

        self::assertNull($body);
        self::assertSame([403, [[
            'relationship_update_not_supported',
            [
                'relationship' => 'albums',
                'type' => 'photos',
                'operation' => OperationKind::ReplaceRelationship->value,
            ],
            '/data/relationships/albums',
        ]]], self::refusals($errors));
    }

    public function testACreateGivesARelationshipItsFirstLinkageWithoutADeclaredReplacement(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "photos", "relationships": {"albums": {"data": [{"type": "albums",'
                . ' "id": "5"}]}}}}'),
            Pointer::root(),
            ['photos'],
            $errors,
        );

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertEquals(
            Linkage::toMany([new Identifier('albums', '5')]),
            $body->relationshipsWithIds()->get('albums'),
        );
    }

    public function testAnUpdateReplacesAToOneRelationshipThatDeclaresNoUpdate(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseUpdateResource(
            self::document('{"data": {"type": "photos", "id": "13", "relationships": {"cover": {"data": {"type":'
                . ' "photos", "id": "7"}}}}}'),
            Pointer::root(),
            'photos',
            '13',
            null,
            $errors,
        );

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertEquals(
            Linkage::toOne(new Identifier('photos', '7')),
            $body->relationshipsWithIds()->get('cover'),
        );
    }

    public function testAToOneRelationshipIsEmptiedWithNull(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(
            self::document('{"data": {"type": "photos", "relationships": {"photographer": {"data": null}}}}'),
            Pointer::root(),
            ['photos'],
            $errors,
        );

        self::assertNull($errors->exception());
        self::assertNotNull($body);
        self::assertEquals(Linkage::toOne(null), $body->relationshipsWithIds()->get('photographer'));
    }

    public function testARelationshipThatIsNotNullableRefusesNull(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseUpdateResource(
            self::document('{"data": {"type": "pets", "id": "1", "relationships": {"owner": {"data": null}}}}'),
            Pointer::root(),
            'pets',
            '1',
            null,
            $errors,
        );

        self::assertNull($body);
        self::assertSame(
            [422, [['unprocessable_content', [], '/data/relationships/owner/data']]],
            self::refusals($errors),
        );
    }

    #[DataProvider('malformedRelationships')]
    public function testACreateRefusesARelationshipThatIsNoLinkageOfIts(string $document, string $pointer): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(self::document($document), Pointer::root(), ['photos'], $errors);

        self::assertNull($body);
        self::assertSame([400, [['bad_request', [], $pointer]]], self::refusals($errors));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedRelationships(): array
    {
        return [
            'a relationship that is no object' => [
                '{"data": {"type": "photos", "relationships": {"tags": []}}}',
                '/data/relationships/tags',
            ],
            'a relationship object without data' => [
                '{"data": {"type": "photos", "relationships": {"tags": {"meta": {}}}}}',
                '/data/relationships/tags',
            ],
            'a to-many linkage that is no array' => [
                '{"data": {"type": "photos", "relationships": {"tags": {"data": {"type": "tags", "id": "2"}}}}}',
                '/data/relationships/tags/data',
            ],
            'a to-many linkage that is null' => [
                '{"data": {"type": "photos", "relationships": {"tags": {"data": null}}}}',
                '/data/relationships/tags/data',
            ],
            'a relationships member that is no object' => [
                '{"data": {"type": "photos", "relationships": []}}',
                '/data/relationships',
            ],
            'an attributes member that is no object' => [
                '{"data": {"type": "photos", "attributes": []}}',
                '/data/attributes',
            ],
        ];
    }

    public function testAReplacementOfAToOneRelationshipCarriesOneIdentifierOrNone(): void
    {
        $errors = self::errors();
        $parser = self::parser();
        $relationship = self::relationshipOf('photos', 'photographer');

        $linkage = $parser->parseReplaceRelationship(
            self::document('{"data": {"type": "customers", "id": "12"}}'),
            Pointer::root(),
            $relationship,
            $errors,
        );
        $empty = $parser->parseReplaceRelationship(
            self::document('{"data": null}'),
            Pointer::root(),
            $relationship,
            $errors,
        );

        self::assertNull($errors->exception());
        self::assertNotNull($linkage);
        self::assertNotNull($empty);
        self::assertEquals(Linkage::toOne(new Identifier('customers', '12')), $linkage->toLinkage());
        self::assertEquals(Linkage::toOne(null), $empty->toLinkage());
    }

    /**
     * @param Closure(
     *     DocumentParser,
     *     mixed,
     *     Pointer,
     *     RelationshipMetadata,
     *     ErrorList,
     * ): ?LocalLinkage $write
     */
    #[DataProvider('relationshipWrites')]
    public function testARelationshipWriteCarriesTheIdentifiersOfItsBody(Closure $write): void
    {
        $errors = self::errors();

        $linkage = $write(
            self::parser(),
            self::document('{"data": [{"type": "tags", "id": "2"}, {"type": "tags", "id": "3"}]}'),
            Pointer::root(),
            self::relationshipOf('photos', 'tags'),
            $errors,
        );

        self::assertNull($errors->exception());
        self::assertNotNull($linkage);
        self::assertEquals(
            Linkage::toMany([new Identifier('tags', '2'), new Identifier('tags', '3')]),
            $linkage->toLinkage(),
        );
    }

    /**
     * @param Closure(
     *     DocumentParser,
     *     mixed,
     *     Pointer,
     *     RelationshipMetadata,
     *     ErrorList,
     * ): ?LocalLinkage $write
     */
    #[DataProvider('relationshipWrites')]
    public function testARelationshipWriteRefusesABodyThatIsNoLinkageOfIts(Closure $write): void
    {
        $errors = self::errors();
        $parser = self::parser();
        $relationship = self::relationshipOf('photos', 'tags');

        self::assertNull($write($parser, self::document('{"meta": {}}'), Pointer::root(), $relationship, $errors));
        self::assertNull($write(
            $parser,
            self::document('{"data": {"type": "tags", "id": "2"}}'),
            Pointer::root(),
            $relationship,
            $errors,
        ));
        self::assertSame([400, [
            ['bad_request', [], ''],
            ['bad_request', [], '/data'],
        ]], self::refusals($errors));
    }

    /**
     * @return array<string, array{
     *     Closure(DocumentParser, mixed, Pointer, RelationshipMetadata, ErrorList): ?LocalLinkage,
     * }>
     */
    public static function relationshipWrites(): array
    {
        return [
            'a replacement' => [
                static function (
                    DocumentParser $parser,
                    mixed $document,
                    Pointer $pointer,
                    RelationshipMetadata $relationship,
                    ErrorList $errors,
                ): ?LocalLinkage {
                    return $parser->parseReplaceRelationship($document, $pointer, $relationship, $errors);
                },
            ],
            'an addition' => [
                static function (
                    DocumentParser $parser,
                    mixed $document,
                    Pointer $pointer,
                    RelationshipMetadata $relationship,
                    ErrorList $errors,
                ): ?LocalLinkage {
                    return $parser->parseAddToRelationship($document, $pointer, $relationship, $errors);
                },
            ],
            'a removal' => [
                static function (
                    DocumentParser $parser,
                    mixed $document,
                    Pointer $pointer,
                    RelationshipMetadata $relationship,
                    ErrorList $errors,
                ): ?LocalLinkage {
                    return $parser->parseRemoveFromRelationship($document, $pointer, $relationship, $errors);
                },
            ],
        ];
    }

    public function testEveryPointerIsWrittenFromThePointerOfTheObjectThatCarriesTheData(): void
    {
        $errors = self::errors();
        $pointer = Pointer::root()->descend('atomic:operations')->descend('1');

        $body = self::parser()->parseCreateResource(self::document('{
            "op": "add",
            "data": {"type": "photos", "attributes": {"width": "320"}}
        }'), $pointer, ['photos'], $errors);

        self::assertNull($body);
        self::assertSame(
            [422, [['invalid_type', ['expected' => 'integer'], '/atomic:operations/1/data/attributes/width']]],
            self::refusals($errors),
        );
    }

    public function testCollectsEveryProblemOfOneDocument(): void
    {
        $errors = self::errors();

        $body = self::parser()->parseCreateResource(self::document('{
            "data": {
                "type": "photos",
                "id": "13",
                "attributes": {"width": "320", "checksum": "a"},
                "relationships": {"photographer": {"data": {"type": "tags", "id": "2"}}}
            }
        }'), Pointer::root(), ['photos'], $errors);

        self::assertNull($body);
        self::assertSame([400, [
            ['client_id_not_supported', ['type' => 'photos'], '/data/id'],
            ['invalid_type', ['expected' => 'integer'], '/data/attributes/width'],
            ['read_only_field', ['type' => 'photos', 'field' => 'checksum'], '/data/attributes/checksum'],
            ['type_mismatch', ['type' => 'tags'], '/data/relationships/photographer/data/type'],
        ]], self::refusals($errors));

        $exception = $errors->exception();

        self::assertNotNull($exception);
        self::assertSame([403, 422, 422, 409], array_map(
            static function (ErrorDescription $description): ?int {
                return $description->status;
            },
            $exception->descriptions(),
        ));
    }

    public function testALinkageOfATypeIsReadForEitherCardinality(): void
    {
        $parser = new DocumentParser(EndpointSamples::registry());
        $errors = new ErrorList(new StatusSelector());

        self::assertEquals(
            Linkage::toOne(new Identifier('customers', '2')),
            $parser->parseLinkage(
                self::json('{"data": {"type": "customers", "id": "2"}}'),
                Pointer::root(),
                'customers',
                Cardinality::ToOne,
                $errors,
            )?->toLinkage(),
        );
        self::assertEquals(
            Linkage::toOne(null),
            $parser->parseLinkage(
                self::json('{"data": null}'),
                Pointer::root(),
                'customers',
                Cardinality::ToOne,
                $errors,
            )?->toLinkage(),
        );
        self::assertEquals(
            Linkage::toMany([new Identifier('customers', '2')]),
            $parser->parseLinkage(
                self::json('{"data": [{"type": "customers", "id": "2"}]}'),
                Pointer::root(),
                'customers',
                Cardinality::ToMany,
                $errors,
            )?->toLinkage(),
        );
        self::assertNull($errors->exception());
    }

    /**
     * @param list<array{string, ?string}> $problems code and pointer
     */
    #[DataProvider('refusedLinkages')]
    public function testALinkageOutsideItsShapeIsRefused(
        string $document,
        Cardinality $cardinality,
        array $problems,
    ): void {
        $errors = new ErrorList(new StatusSelector());

        self::assertNull((new DocumentParser(EndpointSamples::registry()))->parseLinkage(
            self::json($document),
            Pointer::root(),
            'customers',
            $cardinality,
            $errors,
        ));
        self::assertSame($problems, array_map(static function (ErrorDescription $description): array {
            return [
                $description->code instanceof ErrorCode ? $description->code->value : $description->code,
                $description->pointer,
            ];
        }, $errors->exception()?->descriptions() ?? []));
    }

    /**
     * @return iterable<string, array{string, Cardinality, list<array{string, ?string}>}>
     */
    public static function refusedLinkages(): iterable
    {
        yield 'no data' => ['{"meta": {}}', Cardinality::ToOne, [['bad_request', '']]];
        yield 'another type' => [
            '{"data": {"type": "pets", "id": "1"}}',
            Cardinality::ToOne,
            [['type_mismatch', '/data/type']],
        ];
        yield 'one identifier where a list stands' => [
            '{"data": {"type": "customers", "id": "1"}}',
            Cardinality::ToMany,
            [['bad_request', '/data']],
        ];
        yield 'an id the codec refuses' => [
            '{"data": [{"type": "customers", "id": "x"}]}',
            Cardinality::ToMany,
            [['pattern_mismatch', '/data/0/id']],
        ];
    }

    private static function json(string $document): mixed
    {
        return json_decode($document, false, flags: JSON_THROW_ON_ERROR);
    }

    private static function parser(): DocumentParser
    {
        return new DocumentParser(self::registry());
    }

    private static function errors(): ErrorList
    {
        return new ErrorList(new StatusSelector());
    }

    private static function document(string $json): mixed
    {
        return json_decode($json, false, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{int, list<array{string, array<string, scalar>, ?string}>}
     */
    private static function refusals(ErrorList $errors): array
    {
        $exception = $errors->exception();

        self::assertNotNull($exception, 'the parser accepted a document it refuses');

        $problems = [];

        foreach ($exception->descriptions() as $description) {
            $problems[] = [
                $description->code instanceof ErrorCode ? $description->code->value : $description->code,
                $description->parameters,
                $description->pointer,
            ];
        }

        return [$exception->status(), $problems];
    }

    private static function relationshipOf(string $type, string $name): RelationshipMetadata
    {
        return self::registry()->get($type)->relationships[$name];
    }

    private static function registry(): ResourceRegistry
    {
        return new ResourceRegistry([
            Samples::resource('photos', [
                Samples::attribute('title', writeRule: new WriteRule(true, true, false)),
                Samples::attribute('src', writeRule: new WriteRule(true, false, false)),
                Samples::attribute('checksum', writeRule: WriteRule::readOnly()),
                Samples::attribute('width', new IntegerCodec(), writeRule: new WriteRule(true, true, false)),
            ], [
                self::toOne('photographer', 'customers', true, [RelationshipUpdate::Replace]),
                self::toOne('cover', 'photos', true, []),
                self::toOne('uploader', 'customers', true, [], new WriteRule(true, false, false)),
                self::toOne(
                    'groomer',
                    'customers',
                    true,
                    [RelationshipUpdate::Replace],
                    new WriteRule(false, true, false),
                ),
                self::toMany(
                    'tags',
                    'tags',
                    [RelationshipUpdate::Replace, RelationshipUpdate::Add, RelationshipUpdate::Remove],
                ),
                self::toMany('albums', 'albums', [RelationshipUpdate::Add]),
            ], Capability::cases()),
            Samples::resource('pets', [
                Samples::attribute('name', writeRule: new WriteRule(true, true, true)),
            ], [
                self::toOne('owner', 'customers', false, [RelationshipUpdate::Replace]),
            ], Capability::cases()),
            Samples::resource('customers', capabilities: Capability::cases()),
            Samples::resource('tags', capabilities: Capability::cases()),
            Samples::resource('albums', capabilities: Capability::cases()),
            Samples::resource(
                'imports',
                [Samples::attribute('label', writeRule: new WriteRule(true, true, false))],
                capabilities: Capability::cases(),
                clientIdPolicy: ClientIdPolicy::Allowed,
                id: new IdMetadata(
                    new DescribedCodec(new Schema(type: JsonType::String)),
                    static function (object $record): string {
                        return 'id';
                    },
                    true,
                ),
            ),
            Samples::resource(
                'codes',
                [Samples::attribute('label', writeRule: new WriteRule(true, true, false))],
                capabilities: Capability::cases(),
                clientIdPolicy: ClientIdPolicy::Required,
                id: new IdMetadata(new UuidCodec(), static function (object $record): string {
                    return 'id';
                }, false),
            ),
            Samples::resource(
                'notes',
                capabilities: Capability::cases(),
                id: new IdMetadata(new TrimmingCodec(), static function (object $record): string {
                    return 'id';
                }, false),
            ),
            // links to `codes` and `notes`, whose id codecs rewrite or refuse the text they read
            Samples::resource('stamps', [], [
                self::toOne('code', 'codes', true, [RelationshipUpdate::Replace]),
                self::toMany('codes', 'codes', [RelationshipUpdate::Replace]),
                self::toOne('note', 'notes', true, [RelationshipUpdate::Replace]),
            ], Capability::cases()),
        ]);
    }

    /**
     * the default write rule requires on create a relationship that is not nullable
     *
     * @param list<RelationshipUpdate> $updates
     * @param ?WriteRule               $writeRule null for one a create and an update both write
     */
    private static function toOne(
        string $name,
        string $relatedType,
        bool $nullable,
        array $updates,
        ?WriteRule $writeRule = null,
    ): RelationshipMetadata {
        return Samples::toOne(
            $name,
            $relatedType,
            nullable: $nullable,
            updates: $updates,
            writeRule: $writeRule ?? new WriteRule(true, true, !$nullable),
        );
    }

    /**
     * @param list<RelationshipUpdate> $updates
     */
    private static function toMany(string $name, string $relatedType, array $updates): RelationshipMetadata
    {
        return Samples::toMany($name, $relatedType, updates: $updates, writeRule: new WriteRule(true, true, false));
    }
}
