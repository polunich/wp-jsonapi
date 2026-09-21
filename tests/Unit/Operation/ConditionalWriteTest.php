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

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\ConditionalWrite;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;

/**
 * `ConditionalWrite`, checked over the five writes that implement it;
 * `CoversNothing` for the reason `OperationTest` gives
 *
 * @internal
 */
#[CoversNothing]
final class ConditionalWriteTest extends TestCase
{
    public function testTheInterfaceExtendsOperationWithThePrecondition(): void
    {
        $interface = new ReflectionClass(ConditionalWrite::class);
        $signatures = [];

        foreach ($interface->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== ConditionalWrite::class) {
                continue;
            }

            $signatures[$method->getName()] = [
                array_map(
                    static function (ReflectionParameter $parameter): string {
                        return $parameter->getName() . ':' . $parameter->getType();
                    },
                    $method->getParameters(),
                ),
                (string) $method->getReturnType(),
            ];
        }

        self::assertTrue($interface->isInterface());
        self::assertSame([Operation::class], $interface->getInterfaceNames());
        self::assertSame(
            [
                'precondition' => [[], '?' . Precondition::class],
                'withPrecondition' => [['precondition:' . Precondition::class], 'static'],
            ],
            $signatures,
        );
    }

    /**
     * `writes()` includes writes that already carry a precondition: the copy replaces it
     */
    #[DataProvider('writes')]
    public function testWithPreconditionCopiesTheWriteWithThePrecondition(ConditionalWrite $write): void
    {
        $members = get_object_vars($write);
        $before = $write->precondition();
        $precondition = Precondition::listedVersions(['v7']);

        $conditional = $write->withPrecondition($precondition);

        self::assertNotSame($write, $conditional);
        self::assertSame($write::class, $conditional::class);
        self::assertSame($precondition, $conditional->precondition());
        self::assertSame($before, $write->precondition());
        self::assertSame($members, get_object_vars($write));
        self::assertSame(array_merge($members, ['precondition' => $precondition]), get_object_vars($conditional));
    }

    #[DataProvider('writesThatShapeTheResponse')]
    public function testWithPreconditionKeepsTheIncludePathsAndTheFieldsets(
        ConditionalWrite $write,
        ?IncludePaths $include,
        SparseFieldsets $fields,
    ): void {
        $conditional = $write->withPrecondition(Precondition::anyVersion());

        self::assertTrue(property_exists($conditional, 'include'));
        self::assertTrue(property_exists($conditional, 'fields'));
        self::assertSame($include, (new ReflectionProperty($conditional, 'include'))->getValue($conditional));
        self::assertSame($fields, (new ReflectionProperty($conditional, 'fields'))->getValue($conditional));
    }

    /**
     * @return iterable<string, array{ConditionalWrite, ?IncludePaths, SparseFieldsets}>
     */
    public static function writesThatShapeTheResponse(): iterable
    {
        $target = new Identifier('pets', '1');
        $languages = new LanguagePreferences([]);
        $includes = [
            'with include paths' => static function (string $relationship): IncludePaths {
                return new IncludePaths([
                    new IncludePath([$relationship]),
                    new IncludePath([$relationship, 'supplier']),
                ]);
            },
            'with the empty include' => static function (string $relationship): IncludePaths {
                return new IncludePaths([]);
            },
            'without include' => static function (string $relationship): ?IncludePaths {
                return null;
            },
        ];

        foreach ($includes as $case => $includeOf) {
            $fields = new SparseFieldsets(['pets' => ['name'], 'customers' => ['name']]);
            $include = $includeOf('owner');

            yield 'update resource ' . $case => [
                new UpdateResource(
                    $target,
                    new Values([]),
                    new Values([]),
                    null,
                    $include,
                    $fields,
                    $languages,
                    new Values([]),
                ),
                $include,
                $fields,
            ];
            yield 'replace relationship ' . $case => [
                new ReplaceRelationship(
                    $target,
                    'owner',
                    Linkage::toOne(null),
                    null,
                    $include,
                    $fields,
                    $languages,
                    new Values([]),
                ),
                $include,
                $fields,
            ];

            $include = $includeOf('tags');

            yield 'add to relationship ' . $case => [
                new AddToRelationship(
                    $target,
                    'tags',
                    Linkage::toMany([]),
                    null,
                    $include,
                    $fields,
                    $languages,
                    new Values([]),
                ),
                $include,
                $fields,
            ];
            yield 'remove from relationship ' . $case => [
                new RemoveFromRelationship(
                    $target,
                    'tags',
                    Linkage::toMany([]),
                    null,
                    $include,
                    $fields,
                    $languages,
                    new Values([]),
                ),
                $include,
                $fields,
            ];
        }
    }

    /**
     * @return iterable<string, array{ConditionalWrite}>
     */
    public static function writes(): iterable
    {
        $target = new Identifier('pets', '1');
        $languages = new LanguagePreferences([]);

        foreach ([
            'without a precondition' => null,
            'with a precondition' => Precondition::anyVersion(),
        ] as $case => $precondition) {
            yield 'update resource ' . $case => [
                new UpdateResource(
                    $target,
                    new Values(['name' => 'Buddy']),
                    new Values(['owner' => Linkage::toOne(new Identifier('customers', '9'))]),
                    $precondition,
                    new IncludePaths([new IncludePath(['owner'])]),
                    new SparseFieldsets(['pets' => ['name']]),
                    $languages,
                    new Values([]),
                ),
            ];
            yield 'delete resource ' . $case => [new DeleteResource(
                $target,
                $precondition,
                $languages,
                new Values([]),
            )];
            yield 'replace relationship ' . $case => [
                new ReplaceRelationship(
                    $target,
                    'owner',
                    Linkage::toOne(null),
                    $precondition,
                    new IncludePaths([new IncludePath(['owner', 'supplier'])]),
                    new SparseFieldsets(['customers' => ['name']]),
                    $languages,
                    new Values([]),
                ),
            ];
            yield 'add to relationship ' . $case => [
                new AddToRelationship(
                    $target,
                    'tags',
                    Linkage::toMany([new Identifier('tags', '2')]),
                    $precondition,
                    new IncludePaths([new IncludePath(['tags'])]),
                    new SparseFieldsets(['tags' => ['name']]),
                    $languages,
                    new Values([]),
                ),
            ];
            yield 'remove from relationship ' . $case => [
                new RemoveFromRelationship(
                    $target,
                    'tags',
                    Linkage::toMany([new Identifier('tags', '3')]),
                    $precondition,
                    new IncludePaths([new IncludePath(['tags'])]),
                    new SparseFieldsets(['tags' => ['name']]),
                    $languages,
                    new Values([]),
                ),
            ];
        }
    }
}
