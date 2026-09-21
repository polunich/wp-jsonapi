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

namespace Polunich\WpJsonApi\Tests\Unit\Access;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Operation\OperationKind;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use stdClass;

/**
 * @internal
 */
#[CoversClass(OperationAttempt::class)]
final class OperationAttemptTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(OperationAttempt::class))->isReadOnly());
    }

    public function testIsBuiltByTheTwoLevelsAndCarriesNoOperation(): void
    {
        $class = new ReflectionClass(OperationAttempt::class);

        self::assertTrue($class->getConstructor()?->isPrivate());
        self::assertSame(
            ['forKind', 'forRecord'],
            array_map(static function (ReflectionMethod $method): string {
                return $method->getName();
            }, $class->getMethods(ReflectionMethod::IS_PUBLIC)),
        );
        self::assertSame(
            [
                ['kind', OperationKind::class],
                ['type', 'string'],
                ['relationship', '?string'],
                ['request', ServerRequest::class],
                ['record', '?object'],
            ],
            array_map(
                static function (ReflectionProperty $property): array {
                    return [$property->getName(), (string) $property->getType()];
                },
                $class->getProperties(ReflectionProperty::IS_PUBLIC),
            ),
        );
    }

    public function testTheFactoriesNameTheirParameters(): void
    {
        $signature = static function (string $method): array {
            return array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                (new ReflectionMethod(OperationAttempt::class, $method))->getParameters(),
            );
        };
        $level1 = [
            ['kind', OperationKind::class],
            ['type', 'string'],
            ['relationship', '?string'],
            ['request', ServerRequest::class],
        ];

        self::assertSame($level1, $signature('forKind'));
        self::assertSame([...$level1, ['record', 'object']], $signature('forRecord'));
    }

    #[DataProvider('everyKind')]
    public function testEveryKindIsCheckedWithoutARecord(OperationKind $kind, ?string $relationship): void
    {
        $request = self::request();

        $attempt = OperationAttempt::forKind(kind: $kind, type: 'pets', relationship: $relationship, request: $request);

        self::assertSame($kind, $attempt->kind);
        self::assertSame('pets', $attempt->type);
        self::assertSame($relationship, $attempt->relationship);
        self::assertSame($request, $attempt->request);
        self::assertNull($attempt->record);
    }

    /**
     * @return iterable<string, array{OperationKind, ?string}>
     */
    public static function everyKind(): iterable
    {
        yield 'fetch collection' => [OperationKind::FetchCollection, null];
        yield 'create resource' => [OperationKind::CreateResource, null];

        yield from self::kindsOnExistingResources();
    }

    #[DataProvider('kindsOnExistingResources')]
    public function testAKindOnAnExistingResourceIsCheckedWithItsRecord(
        OperationKind $kind,
        ?string $relationship,
    ): void {
        $request = self::request();
        $record = new stdClass();

        $attempt = OperationAttempt::forRecord(
            kind: $kind,
            type: 'pets',
            relationship: $relationship,
            request: $request,
            record: $record,
        );

        self::assertSame($kind, $attempt->kind);
        self::assertSame('pets', $attempt->type);
        self::assertSame($relationship, $attempt->relationship);
        self::assertSame($request, $attempt->request);
        self::assertSame($record, $attempt->record);
    }

    /**
     * @return iterable<string, array{OperationKind, ?string}>
     */
    public static function kindsOnExistingResources(): iterable
    {
        yield 'fetch resource' => [OperationKind::FetchResource, null];
        yield 'fetch related' => [OperationKind::FetchRelated, 'orders'];
        yield 'fetch relationship' => [OperationKind::FetchRelationship, 'orders'];
        yield 'update resource' => [OperationKind::UpdateResource, null];
        yield 'delete resource' => [OperationKind::DeleteResource, null];
        yield 'replace relationship' => [OperationKind::ReplaceRelationship, 'owner'];
        yield 'add to relationship' => [OperationKind::AddToRelationship, 'tags'];
        yield 'remove from relationship' => [OperationKind::RemoveFromRelationship, 'tags'];
    }

    #[DataProvider('kindsWithoutRecord')]
    public function testRefusesARecordForAKindThatActsOnNoExistingResource(OperationKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an attempted ' . $kind->value . ' operation acts on no existing resource, so'
            . ' its permission is checked without a record');

        OperationAttempt::forRecord($kind, 'pets', null, self::request(), new stdClass());
    }

    /**
     * @return iterable<string, array{OperationKind}>
     */
    public static function kindsWithoutRecord(): iterable
    {
        yield 'fetch collection' => [OperationKind::FetchCollection];
        yield 'create resource' => [OperationKind::CreateResource];
    }

    #[DataProvider('levels')]
    public function testRefusesAnEmptyType(bool $withRecord): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an attempted delete_resource operation names a resource type, and a type is'
            . ' never empty');

        self::attempt($withRecord, OperationKind::DeleteResource, '', null);
    }

    #[DataProvider('levels')]
    public function testRefusesAnEmptyRelationship(bool $withRecord): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an attempted replace_relationship operation targets a relationship of a'
            . ' parent of type "pets", whose name is never empty');

        self::attempt($withRecord, OperationKind::ReplaceRelationship, 'pets', '');
    }

    #[DataProvider('mismatchedRelationships')]
    public function testRefusesARelationshipThatDoesNotMatchTheKind(
        bool $withRecord,
        OperationKind $kind,
        ?string $relationship,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::attempt($withRecord, $kind, 'pets', $relationship);
    }

    /**
     * @return iterable<string, array{bool, OperationKind, ?string, string}>
     */
    public static function mismatchedRelationships(): iterable
    {
        foreach (self::levels() as $level => [$withRecord]) {
            foreach ([
                OperationKind::FetchRelated,
                OperationKind::FetchRelationship,
                OperationKind::ReplaceRelationship,
                OperationKind::AddToRelationship,
                OperationKind::RemoveFromRelationship,
            ] as $kind) {
                yield $level . ', ' . $kind->value . ' without a relationship' => [
                    $withRecord,
                    $kind,
                    null,
                    'an attempted '
                        . $kind->value
                        . ' operation targets a relationship of a parent of type "pets", and it names none',
                ];
            }

            foreach ([
                OperationKind::FetchResource,
                OperationKind::UpdateResource,
                OperationKind::DeleteResource,
            ] as $kind) {
                yield $level . ', ' . $kind->value . ' with a relationship' => [
                    $withRecord,
                    $kind,
                    'owner',
                    'an attempted '
                        . $kind->value
                        . ' operation on type "pets" targets no relationship, and it names relationship "owner"',
                ];
            }
        }

        yield 'level 1, fetch_collection with a relationship' => [
            false,
            OperationKind::FetchCollection,
            'owner',
            'an attempted fetch_collection operation on type "pets" targets no relationship, and it names'
            . ' relationship "owner"',
        ];
        yield 'level 1, create_resource with a relationship' => [
            false,
            OperationKind::CreateResource,
            'owner',
            'an attempted create_resource operation on type "pets" targets no relationship, and it names'
            . ' relationship "owner"',
        ];
    }

    #[DataProvider('refusalsNamingOutsideValues')]
    public function testEscapesAControlCharacterOfTheTypeAndTheRelationshipInEveryMessage(
        OperationKind $kind,
        string $type,
        ?string $relationship,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        OperationAttempt::forKind($kind, $type, $relationship, self::request());
    }

    /**
     * @return iterable<string, array{OperationKind, string, ?string, string}>
     */
    public static function refusalsNamingOutsideValues(): iterable
    {
        yield 'the type of a kind that names no relationship it targets' => [
            OperationKind::ReplaceRelationship,
            "pe\nts",
            null,
            'an attempted replace_relationship operation targets a relationship of a parent of type "pe\nts", and'
            . ' it names none',
        ];
        yield 'the type and the relationship of a kind that targets none' => [
            OperationKind::UpdateResource,
            "pe\tts",
            "own\0er",
            'an attempted update_resource operation on type "pe\tts" targets no relationship, and it names'
            . ' relationship "own\000er"',
        ];
        yield 'the type of an empty relationship' => [
            OperationKind::AddToRelationship,
            "pe\x7Fts",
            '',
            'an attempted add_to_relationship operation targets a relationship of a parent of type "pe\177ts",'
            . ' whose name is never empty',
        ];
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function levels(): iterable
    {
        yield 'level 1' => [false];
        yield 'level 2' => [true];
    }

    private static function attempt(
        bool $withRecord,
        OperationKind $kind,
        string $type,
        ?string $relationship,
    ): OperationAttempt {
        if ($withRecord) {
            return OperationAttempt::forRecord($kind, $type, $relationship, self::request(), new stdClass());
        }

        return OperationAttempt::forKind($kind, $type, $relationship, self::request());
    }

    private static function request(): ServerRequest
    {
        return new ServerRequest(
            'DELETE',
            new Headers(['Accept' => 'application/vnd.api+json']),
            [],
            ['id' => '13'],
            '',
        );
    }
}
