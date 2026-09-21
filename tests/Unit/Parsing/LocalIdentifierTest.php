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

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Parsing\LocalIdentifier;

/**
 * @internal
 */
#[CoversClass(LocalIdentifier::class)]
final class LocalIdentifierTest extends TestCase
{
    public function testAnIdentifierOfAnIdCarriesTheIdAndNoLid(): void
    {
        $identifier = LocalIdentifier::ofId('customers', '9');

        self::assertSame('customers', $identifier->type);
        self::assertSame('9', $identifier->id());
        self::assertNull($identifier->lid());
    }

    public function testAnIdentifierOfALidCarriesTheLidAndNoId(): void
    {
        $identifier = LocalIdentifier::ofLid('customers', 'owner');

        self::assertSame('customers', $identifier->type);
        self::assertNull($identifier->id());
        self::assertSame('owner', $identifier->lid());
    }

    public function testAnIdentifierOfAnIdNeedsNoIdOfALid(): void
    {
        $identifier = LocalIdentifier::ofId('customers', '9')->toIdentifier();

        self::assertEquals(new Identifier('customers', '9'), $identifier);
    }

    public function testAnIdentifierOfALidBecomesTheIdTheLidStandsFor(): void
    {
        $identifier = LocalIdentifier::ofLid('customers', 'owner');

        $built = $identifier->toIdentifier(static function (string $type, string $lid): string {
            self::assertSame('customers', $type);
            self::assertSame('owner', $lid);

            return '9';
        });

        self::assertEquals(new Identifier('customers', '9'), $built);
    }

    public function testAnIdentifierOfALidRefusesToBecomeAnIdWithoutTheIdOfItsLid(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('names a lid');

        LocalIdentifier::ofLid('customers', 'owner')->toIdentifier();
    }

    #[DataProvider('emptyMembers')]
    public function testAnIdentifierNamesANonEmptyTypeAndANonEmptyId(string $type, string $id, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        LocalIdentifier::ofId($type, $id);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function emptyMembers(): array
    {
        return [
            'no type' => ['', '9', 'a type is never empty'],
            'no id' => ['customers', '', 'carries an empty id'],
        ];
    }

    public function testAnIdentifierOfALidRefusesTheEmptyLid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('carries an empty lid');

        LocalIdentifier::ofLid('customers', '');
    }
}
