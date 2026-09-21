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

namespace Polunich\WpJsonApi\Tests\Unit\Codec;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\ConstCodec;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\EnumCodec;
use Polunich\WpJsonApi\Codec\NullableCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\Shade;

/**
 * @internal
 */
#[CoversClass(NullableCodec::class)]
final class NullableCodecTest extends TestCase
{
    public function testNullIsDecodedAsNull(): void
    {
        self::assertNull((new NullableCodec(new StringCodec()))->decode(null, Pointer::root()));
    }

    public function testAValueIsDecodedByTheInnerCodec(): void
    {
        self::assertSame('a name', (new NullableCodec(new StringCodec()))->decode('a name', Pointer::root()));
    }

    public function testAViolationOfTheInnerCodecReachesTheCaller(): void
    {
        try {
            (new NullableCodec(new StringCodec()))->decode(1, Pointer::root()->descend('name'));
        } catch (DecodeException $exception) {
            $violation = $exception->violations()[0];

            self::assertSame('invalid_type', $violation->code);
            self::assertSame('/name', $violation->pointer->toString());

            return;
        }

        self::fail('the codec accepted the value');
    }

    public function testTheSchemaAddsTheNullTypeToTheTypeOfTheInnerCodec(): void
    {
        $schema = (new NullableCodec(new StringCodec(maxLength: 200)))->schema()->toObject();

        self::assertSame(['type' => ['string', 'null'], 'maxLength' => 200], get_object_vars($schema));
    }

    public function testTheSchemaOfANullableEnumAllowsNull(): void
    {
        $schema = (new NullableCodec(new EnumCodec(Shade::class)))->schema()->toObject();

        self::assertSame(
            ['type' => ['string', 'null'], 'enum' => ['light', 'dark', null]],
            get_object_vars($schema),
        );
    }

    public function testTheSchemaOfANullableConstantUsesAnyOf(): void
    {
        $schema = (new NullableCodec(new ConstCodec('pets')))->schema()->toObject();

        self::assertSame(
            '{"anyOf":[{"type":"string","const":"pets"},{"type":"null"}]}',
            json_encode($schema),
        );
    }

    public function testEncodeWritesNullAsNull(): void
    {
        self::assertNull((new NullableCodec(new StringCodec()))->encode(null));
    }

    public function testEncodeHandsAValueToTheInnerCodec(): void
    {
        self::assertSame('a name', (new NullableCodec(new StringCodec()))->encode('a name'));
    }

    public function testEncodeRefusesAValueTheInnerCodecRefuses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must be a string');

        (new NullableCodec(new StringCodec()))->encode(1);
    }
}
