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

use Closure;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\AtomicOperation;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(AtomicOperation::class)]
final class AtomicOperationTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(AtomicOperation::class))->isReadOnly());
    }

    public function testTheClassIsInternal(): void
    {
        self::assertStringContainsString(
            '@internal',
            (string) (new ReflectionClass(AtomicOperation::class))->getDocComment(),
        );
    }

    public function testIsNoOperation(): void
    {
        self::assertFalse((new ReflectionClass(AtomicOperation::class))->implementsInterface(Operation::class));
    }

    public function testTheKnownMembersAndBuildKeepTheirNamesAndTypes(): void
    {
        $class = new ReflectionClass(AtomicOperation::class);

        self::assertSame(
            [
                ['kind', OperationKind::class, true],
                ['type', 'string', true],
                ['relationship', '?string', true],
                ['lid', '?string', true],
                ['build', Closure::class, false],
            ],
            array_map(
                static function (ReflectionProperty $property): array {
                    return [
                        $property->getName(),
                        (string) $property->getType(),
                        $property->isPublic(),
                    ];
                },
                $class->getProperties(),
            ),
        );
        self::assertSame(
            [
                ['kind', OperationKind::class],
                ['type', 'string'],
                ['relationship', '?string'],
                ['lid', '?string'],
                ['build', Closure::class],
            ],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getConstructor()?->getParameters() ?? [],
            ),
        );

        $build = $class->getMethod('build');

        self::assertSame(
            [['idOfLid', Closure::class]],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $build->getParameters(),
            ),
        );
        self::assertSame(Operation::class, (string) $build->getReturnType());
    }

    public function testCarriesWhatIsKnownBeforeTheOperationIsBuilt(): void
    {
        $operation = new AtomicOperation(
            kind: OperationKind::CreateResource,
            type: 'pets',
            relationship: null,
            lid: 'draft',
            build: static function (Closure $idOfLid): Operation {
                return self::create();
            },
        );

        self::assertSame(OperationKind::CreateResource, $operation->kind);
        self::assertSame('pets', $operation->type);
        self::assertNull($operation->relationship);
        self::assertSame('draft', $operation->lid);
    }

    public function testBuildsTheOperationWithTheIdOfEachLidItRefersTo(): void
    {
        $asked = [];
        $idOfLid = static function (string $type, string $lid) use (&$asked): string {
            $asked[] = [$type, $lid];

            return $type . '-' . $lid;
        };
        $operation = new AtomicOperation(
            OperationKind::ReplaceRelationship,
            'pets',
            'owner',
            null,
            static function (Closure $idOfLid): Operation {
                return new ReplaceRelationship(
                    new Identifier('pets', $idOfLid('pets', 'draft')),
                    'owner',
                    Linkage::toOne(new Identifier('customers', $idOfLid('customers', 'ada'))),
                    null,
                    null,
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
        );

        $operationBuilt = $operation->build($idOfLid);

        self::assertInstanceOf(ReplaceRelationship::class, $operationBuilt);
        self::assertSame([['pets', 'draft'], ['customers', 'ada']], $asked);
        self::assertSame('pets-draft', $operationBuilt->parent->id);
        self::assertSame('customers-ada', $operationBuilt->linkage->identifier()?->id);
    }

    /**
     * `build()` memoizes nothing: each call builds a new, equal operation
     */
    public function testEveryResolutionBuildsAnOperation(): void
    {
        $operation = new AtomicOperation(
            OperationKind::DeleteResource,
            'pets',
            null,
            null,
            static function (Closure $idOfLid): Operation {
                return new DeleteResource(
                    new Identifier('pets', '1'),
                    null,
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
        );
        $idOfLid = static function (string $type, string $lid): string {
            return 'unused';
        };

        $first = $operation->build($idOfLid);

        self::assertNotSame($first, $operation->build($idOfLid));
        self::assertEquals($first, $operation->build($idOfLid));
    }

    #[DataProvider('writes')]
    public function testTakesEveryWrite(
        OperationKind $kind,
        ?string $relationship,
        ?string $lid,
        Operation $built,
    ): void {
        $operation = new AtomicOperation(
            $kind,
            'pets',
            $relationship,
            $lid,
            static function (Closure $idOfLid) use ($built): Operation {
                return $built;
            },
        );

        self::assertSame($built, $operation->build(static function (string $type, string $lid): string {
            return 'unused';
        }));
    }

    /**
     * @return iterable<string, array{OperationKind, ?string, ?string, Operation}>
     */
    public static function writes(): iterable
    {
        $target = new Identifier('pets', '1');
        $languages = new LanguagePreferences([]);

        yield 'create with a lid' => [OperationKind::CreateResource, null, 'draft', self::create()];
        yield 'create without a lid' => [OperationKind::CreateResource, null, null, self::create()];
        yield 'update' => [
            OperationKind::UpdateResource,
            null,
            null,
            new UpdateResource(
                $target,
                new Values([]),
                new Values([]),
                null,
                null,
                new SparseFieldsets([]),
                $languages,
                new Values([]),
            ),
        ];
        yield 'delete' => [OperationKind::DeleteResource, null, null, new DeleteResource(
            $target,
            null,
            $languages,
            new Values([]),
        )];
        yield 'replace' => [
            OperationKind::ReplaceRelationship,
            'owner',
            null,
            new ReplaceRelationship(
                $target,
                'owner',
                Linkage::toOne(null),
                null,
                null,
                new SparseFieldsets([]),
                $languages,
                new Values([]),
            ),
        ];
        yield 'add' => [
            OperationKind::AddToRelationship,
            'tags',
            null,
            new AddToRelationship(
                $target,
                'tags',
                Linkage::toMany([]),
                null,
                null,
                new SparseFieldsets([]),
                $languages,
                new Values([]),
            ),
        ];
        yield 'remove' => [
            OperationKind::RemoveFromRelationship,
            'tags',
            null,
            new RemoveFromRelationship(
                $target,
                'tags',
                Linkage::toMany([]),
                null,
                null,
                new SparseFieldsets([]),
                $languages,
                new Values([]),
            ),
        ];
    }

    #[DataProvider('fetches')]
    public function testRefusesAFetch(OperationKind $kind, ?string $relationship): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an atomic operation adds, updates or removes, and this one is ' . $kind->value);

        self::operation($kind, 'pets', $relationship, null);
    }

    /**
     * @return iterable<string, array{OperationKind, ?string}>
     */
    public static function fetches(): iterable
    {
        yield 'fetch collection' => [OperationKind::FetchCollection, null];
        yield 'fetch resource' => [OperationKind::FetchResource, null];
        yield 'fetch related' => [OperationKind::FetchRelated, 'orders'];
        yield 'fetch relationship' => [OperationKind::FetchRelationship, 'orders'];
    }

    public function testRefusesAnEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an atomic update_resource operation names a resource type, and a type is'
            . ' never empty');

        self::operation(OperationKind::UpdateResource, '', null, null);
    }

    #[DataProvider('mismatchedRelationships')]
    public function testRefusesARelationshipThatDoesNotMatchTheKind(
        OperationKind $kind,
        ?string $relationship,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::operation($kind, 'pets', $relationship, null);
    }

    /**
     * @return iterable<string, array{OperationKind, ?string, string}>
     */
    public static function mismatchedRelationships(): iterable
    {
        foreach ([
            OperationKind::ReplaceRelationship,
            OperationKind::AddToRelationship,
            OperationKind::RemoveFromRelationship,
        ] as $kind) {
            yield $kind->value . ' without a relationship' => [
                $kind,
                null,
                'an atomic ' . $kind->value . ' operation on type "pets" targets a relationship, and it names none',
            ];
        }

        foreach ([
            OperationKind::CreateResource,
            OperationKind::UpdateResource,
            OperationKind::DeleteResource,
        ] as $kind) {
            yield $kind->value . ' with a relationship' => [
                $kind,
                'owner',
                'an atomic '
                    . $kind->value
                    . ' operation on type "pets" targets no relationship, and it names relationship "owner"',
            ];
        }
    }

    public function testRefusesAnEmptyRelationship(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an atomic add_to_relationship operation on type "pets" targets a'
            . ' relationship, whose name is never empty');

        self::operation(OperationKind::AddToRelationship, 'pets', '', null);
    }

    #[DataProvider('writesThatCreateNothing')]
    public function testRefusesALidOnAKindThatCreatesNoResource(OperationKind $kind, ?string $relationship): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an atomic ' . $kind->value . ' operation on type "pets" creates no resource,'
            . ' so it gives no lid');

        self::operation($kind, 'pets', $relationship, 'draft');
    }

    /**
     * @return iterable<string, array{OperationKind, ?string}>
     */
    public static function writesThatCreateNothing(): iterable
    {
        yield 'update' => [OperationKind::UpdateResource, null];
        yield 'delete' => [OperationKind::DeleteResource, null];
        yield 'replace' => [OperationKind::ReplaceRelationship, 'owner'];
        yield 'add' => [OperationKind::AddToRelationship, 'tags'];
        yield 'remove' => [OperationKind::RemoveFromRelationship, 'tags'];
    }

    public function testRefusesAnEmptyLid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an atomic create_resource operation gives the resource of type "pets" an'
            . ' empty lid, which identifies no resource');

        self::operation(OperationKind::CreateResource, 'pets', null, '');
    }

    #[DataProvider('differentOperations')]
    public function testRefusesAnOperationBuiltWithAnotherKindTypeOrRelationship(
        Operation $built,
        string $description,
    ): void {
        $operation = new AtomicOperation(
            OperationKind::ReplaceRelationship,
            'pets',
            'owner',
            null,
            static function (Closure $idOfLid) use ($built): Operation {
                return $built;
            },
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'the atomic operation replace_relationship on relationship "owner" of type "pets" was built as '
            . $description
            . ', and building keeps the kind, the type and the relationship',
        );

        $operation->build(static function (string $type, string $lid): string {
            return 'unused';
        });
    }

    /**
     * @return iterable<string, array{Operation, string}>
     */
    public static function differentOperations(): iterable
    {
        $languages = new LanguagePreferences([]);

        yield 'another kind' => [
            new DeleteResource(new Identifier('pets', '1'), null, $languages, new Values([])),
            'delete_resource on type "pets"',
        ];
        yield 'another kind on the same relationship' => [
            new AddToRelationship(
                new Identifier('pets', '1'),
                'owner',
                Linkage::toMany([]),
                null,
                null,
                new SparseFieldsets([]),
                $languages,
                new Values([]),
            ),
            'add_to_relationship on relationship "owner" of type "pets"',
        ];
        yield 'another type' => [
            new ReplaceRelationship(
                new Identifier('customers', '1'),
                'owner',
                Linkage::toOne(null),
                null,
                null,
                new SparseFieldsets([]),
                $languages,
                new Values([]),
            ),
            'replace_relationship on relationship "owner" of type "customers"',
        ];
        yield 'another relationship' => [
            new ReplaceRelationship(
                new Identifier('pets', '1'),
                'breeder',
                Linkage::toOne(null),
                null,
                null,
                new SparseFieldsets([]),
                $languages,
                new Values([]),
            ),
            'replace_relationship on relationship "breeder" of type "pets"',
        ];
    }

    /**
     * `10` and `1e1` are equal under `==`,
     * so a loose comparison would let the built operation through
     */
    #[DataProvider('namesEqualAsNumbers')]
    public function testComparesTheTypeAndTheRelationshipAsStrings(
        string $type,
        string $relationship,
        Operation $built,
        string $message,
    ): void {
        $operation = new AtomicOperation(
            OperationKind::ReplaceRelationship,
            $type,
            $relationship,
            null,
            static function (Closure $idOfLid) use ($built): Operation {
                return $built;
            },
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        $operation->build(static function (string $type, string $lid): string {
            return 'unused';
        });
    }

    /**
     * @return iterable<string, array{string, string, Operation, string}>
     */
    public static function namesEqualAsNumbers(): iterable
    {
        $languages = new LanguagePreferences([]);

        yield 'a type' => [
            '10',
            'owner',
            new ReplaceRelationship(
                new Identifier('1e1', '1'),
                'owner',
                Linkage::toOne(null),
                null,
                null,
                new SparseFieldsets([]),
                $languages,
                new Values([]),
            ),
            'the atomic operation replace_relationship on relationship "owner" of type "10" was built as'
            . ' replace_relationship on relationship "owner" of type "1e1"',
        ];
        yield 'a relationship' => [
            'pets',
            '10',
            new ReplaceRelationship(
                new Identifier('pets', '1'),
                '1e1',
                Linkage::toOne(null),
                null,
                null,
                new SparseFieldsets([]),
                $languages,
                new Values([]),
            ),
            'the atomic operation replace_relationship on relationship "10" of type "pets" was built as'
            . ' replace_relationship on relationship "1e1" of type "pets"',
        ];
    }

    #[DataProvider('refusalsNamingOutsideValues')]
    public function testEscapesAControlCharacterOfTheTypeAndTheRelationshipInEveryRefusal(
        OperationKind $kind,
        string $type,
        ?string $relationship,
        ?string $lid,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::operation($kind, $type, $relationship, $lid);
    }

    /**
     * @return iterable<string, array{OperationKind, string, ?string, ?string, string}>
     */
    public static function refusalsNamingOutsideValues(): iterable
    {
        yield 'the type of a kind that names no relationship it targets' => [
            OperationKind::ReplaceRelationship,
            "pe\nts",
            null,
            null,
            'an atomic replace_relationship operation on type "pe\nts" targets a relationship, and it names none',
        ];
        yield 'the type and the relationship of a kind that targets none' => [
            OperationKind::UpdateResource,
            "pe\tts",
            "own\0er",
            null,
            'an atomic update_resource operation on type "pe\tts" targets no relationship, and it names'
            . ' relationship "own\000er"',
        ];
        yield 'the type of an empty relationship' => [
            OperationKind::AddToRelationship,
            "pe\x7Fts",
            '',
            null,
            'an atomic add_to_relationship operation on type "pe\177ts" targets a relationship, whose name is'
            . ' never empty',
        ];
        yield 'the type of a lid on a kind that creates nothing' => [
            OperationKind::DeleteResource,
            "pe\rts",
            null,
            'draft',
            'an atomic delete_resource operation on type "pe\rts" creates no resource, so it gives no lid',
        ];
        yield 'the type of an empty lid' => [
            OperationKind::CreateResource,
            "pe\x1Bts",
            null,
            '',
            'an atomic create_resource operation gives the resource of type "pe\033ts" an empty lid, which'
            . ' identifies no resource',
        ];
    }

    #[DataProvider('differentOperationsNamingOutsideValues')]
    public function testEscapesAControlCharacterOfTheTypeAndTheRelationshipWhenTheBuiltOperationDiffers(
        OperationKind $kind,
        string $type,
        ?string $relationship,
        Operation $built,
        string $message,
    ): void {
        $operation = new AtomicOperation(
            $kind,
            $type,
            $relationship,
            null,
            static function (Closure $idOfLid) use ($built): Operation {
                return $built;
            },
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        $operation->build(static function (string $type, string $lid): string {
            return 'unused';
        });
    }

    /**
     * @return iterable<string, array{OperationKind, string, ?string, Operation, string}>
     */
    public static function differentOperationsNamingOutsideValues(): iterable
    {
        $languages = new LanguagePreferences([]);

        yield 'the types of operations on no relationship' => [
            OperationKind::DeleteResource,
            "pe\nts",
            null,
            new DeleteResource(new Identifier("cust\tomers", '1'), null, $languages, new Values([])),
            'the atomic operation delete_resource on type "pe\nts" was built as delete_resource on type "cust\tomers",'
            . ' and building keeps the kind, the type and the relationship',
        ];
        yield 'the relationships and the types of operations on a relationship' => [
            OperationKind::ReplaceRelationship,
            "pe\nts",
            "own\0er",
            new ReplaceRelationship(
                new Identifier("cust\tomers", '1'),
                "bree\rder",
                Linkage::toOne(null),
                null,
                null,
                new SparseFieldsets([]),
                $languages,
                new Values([]),
            ),
            'the atomic operation replace_relationship on relationship "own\000er" of type "pe\nts" was built as'
            . ' replace_relationship on relationship "bree\rder" of type "cust\tomers", and building keeps the kind,'
            . ' the type and the relationship',
        ];
    }

    public function testTheExceptionOfTheResolutionPassesUnchanged(): void
    {
        $refusal = new RuntimeException('no prior operation assigned lid "draft"');
        $operation = new AtomicOperation(
            OperationKind::DeleteResource,
            'pets',
            null,
            null,
            static function (Closure $idOfLid): Operation {
                return new DeleteResource(
                    new Identifier('pets', $idOfLid('pets', 'draft')),
                    null,
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
        );

        try {
            $operation->build(static function (string $type, string $lid) use ($refusal): string {
                throw $refusal;
            });
            self::fail('the lid without an id was replaced');
        } catch (RuntimeException $caught) {
            self::assertSame($refusal, $caught);
        }
    }

    private static function operation(
        OperationKind $kind,
        string $type,
        ?string $relationship,
        ?string $lid,
    ): AtomicOperation {
        return new AtomicOperation($kind, $type, $relationship, $lid, static function (Closure $idOfLid): Operation {
            return self::create();
        });
    }

    private static function create(): CreateResource
    {
        return new CreateResource(
            'pets',
            null,
            new Values([]),
            new Values([]),
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }
}
