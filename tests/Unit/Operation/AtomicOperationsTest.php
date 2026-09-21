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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Operation\AtomicOperation;
use Polunich\WpJsonApi\Operation\AtomicOperations;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(AtomicOperations::class)]
final class AtomicOperationsTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(AtomicOperations::class))->isReadOnly());
    }

    public function testTheClassIsInternal(): void
    {
        self::assertStringContainsString(
            '@internal',
            (string) (new ReflectionClass(AtomicOperations::class))->getDocComment(),
        );
    }

    public function testTheListIsNoOperation(): void
    {
        self::assertFalse((new ReflectionClass(AtomicOperations::class))->implementsInterface(Operation::class));
    }

    public function testKeepsTheEntriesInTheOrderOfTheRequest(): void
    {
        $owner = self::create('owners', 'ada');
        $pet = self::create('pets', 'rex');
        $link = new AtomicOperation(
            OperationKind::ReplaceRelationship,
            'pets',
            'owner',
            null,
            static function (Closure $idOfLid): Operation {
                return new ReplaceRelationship(
                    new Identifier('pets', $idOfLid('pets', 'rex')),
                    'owner',
                    Linkage::toOne(new Identifier('owners', $idOfLid('owners', 'ada'))),
                    null,
                    null,
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
        );

        $operations = new AtomicOperations(operations: [$owner, $pet, $link]);

        self::assertSame([0 => $owner, 1 => $pet, 2 => $link], $operations->all());
    }

    public function testRefusesAnEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an atomic request holds one or more operations, and this one holds none');

        new AtomicOperations([]);
    }

    private static function create(string $type, string $lid): AtomicOperation
    {
        return new AtomicOperation(
            OperationKind::CreateResource,
            $type,
            null,
            $lid,
            static function (Closure $idOfLid) use ($type): Operation {
                return new CreateResource(
                    $type,
                    null,
                    new Values([]),
                    new Values([]),
                    null,
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
        );
    }
}
