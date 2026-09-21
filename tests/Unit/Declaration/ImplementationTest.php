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

namespace Polunich\WpJsonApi\Tests\Unit\Declaration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Declaration\Implementation;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\PoliteGreeter;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Implementation::class)]
final class ImplementationTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndPublicApi(): void
    {
        $class = new ReflectionClass(Implementation::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringNotContainsString('@internal', (string) $class->getDocComment());
        self::assertStringContainsString('@internal', (string) $class->getMethod('build')->getDocComment());
    }

    public function testAServiceNamesAContainerEntry(): void
    {
        $reference = Implementation::service('acme.pets.reader')->build();

        self::assertSame('acme.pets.reader', $reference->entryId);
        self::assertNull($reference->instance);
        self::assertNull($reference->factory);
    }

    public function testAnInstanceCarriesTheImplementationItself(): void
    {
        $implementation = new PoliteGreeter();
        $reference = Implementation::instance($implementation)->build();

        self::assertSame($implementation, $reference->instance);
        self::assertNull($reference->entryId);
        self::assertNull($reference->factory);
    }

    public function testAFactoryCarriesTheClosure(): void
    {
        $factory = static function (): PoliteGreeter {
            return new PoliteGreeter();
        };
        $reference = Implementation::factory($factory)->build();

        self::assertSame($factory, $reference->factory);
        self::assertNull($reference->entryId);
        self::assertNull($reference->instance);
    }

    public function testRefusesAnEmptyEntryIdWhereItIsWritten(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an implementation is looked up in the container by its entry id, which is'
            . ' never empty');

        Implementation::service('');
    }

    public function testReturnsTheSameReferenceEveryTime(): void
    {
        $implementation = Implementation::service('acme.pets.reader');

        self::assertSame($implementation->build(), $implementation->build());
    }
}
