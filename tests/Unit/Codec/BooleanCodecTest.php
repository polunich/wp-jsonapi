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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\BooleanCodec;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Schema\JsonType;

/**
 * @internal
 */
#[CoversClass(BooleanCodec::class)]
final class BooleanCodecTest extends TestCase
{
    public function testTrueAndFalseAreReturnedAsTheyAre(): void
    {
        $codec = new BooleanCodec();

        self::assertTrue($codec->decode(true, Pointer::root()));
        self::assertFalse($codec->decode(false, Pointer::root()));
    }

    #[DataProvider('valuesThatAreNoBoolean')]
    public function testAValueOfAnotherTypeIsRefused(mixed $input): void
    {
        try {
            (new BooleanCodec())->decode($input, Pointer::root());
        } catch (DecodeException $exception) {
            $violation = $exception->violations()[0];

            self::assertSame('invalid_type', $violation->code);
            self::assertSame(['expected' => JsonType::Boolean->value], $violation->parameters);

            return;
        }

        self::fail('the codec accepted the value');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesThatAreNoBoolean(): iterable
    {
        yield 'null' => [null];
        yield 'the text true' => ['true'];
        yield 'the text 1' => ['1'];
        yield 'the number one' => [1];
        yield 'the number zero' => [0];
        yield 'an empty string' => [''];
        yield 'a list' => [[true]];
        yield 'an object' => [(object) ['a' => true]];
    }

    public function testTheSchemaNamesTheBooleanType(): void
    {
        self::assertSame(['type' => 'boolean'], get_object_vars((new BooleanCodec())->schema()->toObject()));
    }

    public function testEncodeReturnsTheBoolean(): void
    {
        self::assertTrue((new BooleanCodec())->encode(true));
        self::assertFalse((new BooleanCodec())->encode(false));
    }

    public function testEncodeRefusesAValueOfAnotherType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must be a boolean');

        (new BooleanCodec())->encode(1);
    }
}
