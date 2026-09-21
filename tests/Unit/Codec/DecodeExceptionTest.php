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
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\RejectedValueException;
use Polunich\WpJsonApi\Codec\Violation;

/**
 * @internal
 */
#[CoversClass(DecodeException::class)]
final class DecodeExceptionTest extends TestCase
{
    public function testAnEmptyListOfViolationsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a decode exception must carry at least one violation');

        new DecodeException([]);
    }

    public function testViolationsAreKeptInTheOrderTheyWereGiven(): void
    {
        $first = new Violation(Pointer::root()->descend('a'), 'invalid_type', ['expected' => 'string']);
        $second = new Violation(Pointer::root()->descend('b'), 'missing_member', ['member' => 'b']);

        $exception = new DecodeException([$first, $second]);

        self::assertSame([$first, $second], $exception->violations());
    }

    public function testTheMessageNamesEveryCodeAndItsPointer(): void
    {
        $exception = new DecodeException([
            new Violation(Pointer::root()->descend('data')->descend('id'), 'invalid_type'),
            new Violation(Pointer::root(), 'missing_member'),
        ]);

        self::assertSame(
            'the value could not be decoded: invalid_type at "/data/id", missing_member at ""',
            $exception->getMessage(),
        );
    }

    public function testThePreviousThrowableIsCarried(): void
    {
        $previous = new RejectedValueException('slug_taken');

        $exception = new DecodeException([new Violation(Pointer::root(), 'slug_taken')], $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    public function testTheRefusalOfEncodeNamesEveryCodeAndItsPointer(): void
    {
        $previous = new DecodeException([new Violation(Pointer::root(), 'value_too_long')]);
        $refusal = DecodeException::encodeRefusal(
            [new Violation(Pointer::root()->descend('0'), 'value_too_long'), new Violation(Pointer::root(), 'x')],
            $previous,
        );

        self::assertSame(
            'the value is not one decode() returns, which refuses it: value_too_long at "/0", x at ""',
            $refusal->getMessage(),
        );
        self::assertSame($previous, $refusal->getPrevious());
    }

    public function testTheIntegerCodeIsZero(): void
    {
        $exception = new DecodeException([new Violation(Pointer::root(), 'invalid_type')]);

        self::assertSame(0, $exception->getCode());
    }
}
