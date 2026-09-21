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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\RejectedValueException;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(RejectedValueException::class)]
final class RejectedValueExceptionTest extends TestCase
{
    public function testTheDefaultViolationCodeIsValueRejected(): void
    {
        $exception = new RejectedValueException();

        self::assertSame('value_rejected', $exception->violationCode());
        self::assertSame([], $exception->parameters());
    }

    public function testTheViolationCodeAndTheParametersAreKept(): void
    {
        $exception = new RejectedValueException('slug_taken', ['slug' => 'the-slug']);

        self::assertSame('slug_taken', $exception->violationCode());
        self::assertSame(['slug' => 'the-slug'], $exception->parameters());
    }

    public function testTheMessageNamesTheViolationCode(): void
    {
        $exception = new RejectedValueException('slug_taken');

        self::assertSame('the decoded value was rejected: slug_taken', $exception->getMessage());
    }

    public function testTheIntegerCodeOfTheExceptionIsUnused(): void
    {
        $exception = new RejectedValueException('slug_taken');

        self::assertSame(0, $exception->getCode());
    }

    public function testThePreviousThrowableIsCarried(): void
    {
        $previous = new RuntimeException('the store said no');

        $exception = new RejectedValueException('slug_taken', previous: $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    public function testThePathIsEmptyUnlessAMemberIsNamed(): void
    {
        self::assertSame([], (new RejectedValueException('slug_taken'))->path());
        self::assertSame(
            ['schedule', 'end'],
            (new RejectedValueException('end_before_start', path: ['schedule', 'end']))->path(),
        );
    }
}
