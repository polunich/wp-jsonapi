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

namespace Polunich\WpJsonApi\Tests\Unit\Execution\Atomic;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\UnauthorizedException;
use Polunich\WpJsonApi\Execution\Atomic\OperationFailedException;
use ReflectionClass;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(OperationFailedException::class)]
final class OperationFailedExceptionTest extends TestCase
{
    public function testTheFailureIsCarriedAsItWasThrown(): void
    {
        $failure = new RuntimeException('the store refused the write');

        $wrapper = new OperationFailedException(2, $failure);

        self::assertSame($failure, $wrapper->failure);
        self::assertSame(2, $wrapper->position);
    }

    public function testTheFailureIsAlsoThePreviousException(): void
    {
        $failure = new RuntimeException('the store refused the write');

        self::assertSame($failure, (new OperationFailedException(0, $failure))->getPrevious());
    }

    public function testTheMessageNamesThePositionAndTheFailure(): void
    {
        $wrapper = new OperationFailedException(3, new RuntimeException('the store refused the write'));

        self::assertStringContainsString('3', $wrapper->getMessage());
        self::assertStringContainsString('the store refused the write', $wrapper->getMessage());
    }

    public function testAControlCharacterOfTheFailureCannotBreakTheLogLine(): void
    {
        $wrapper = new OperationFailedException(0, new RuntimeException("first line\nsecond line"));

        self::assertStringNotContainsString("\n", $wrapper->getMessage());
        self::assertStringContainsString('first line', $wrapper->getMessage());
    }

    public function testTheMembersOfTheFailureStayReadable(): void
    {
        $challenge = new Challenge('Basic', ['realm' => 'api']);
        $failure = new UnauthorizedException([new ErrorDescription(ErrorCode::Unauthorized)], [$challenge]);

        $carried = (new OperationFailedException(1, $failure))->failure;

        self::assertInstanceOf(UnauthorizedException::class, $carried);
        self::assertSame([$challenge], $carried->challenges());
        self::assertSame(401, $carried->status());
    }

    public function testTheWrapperIsNoJsonApiException(): void
    {
        $parents = class_parents(new OperationFailedException(0, new RuntimeException('failed')));

        self::assertNotContains(JsonApiException::class, $parents);
        self::assertContains(RuntimeException::class, $parents);
    }

    public function testTheFirstOperationStandsAtPositionZero(): void
    {
        self::assertSame(0, (new OperationFailedException(0, new RuntimeException('failed')))->position);
    }

    #[DataProvider('positionsThatNameNoOperation')]
    public function testAPositionBelowZeroIsRefused(int $position): void
    {
        try {
            new OperationFailedException($position, new RuntimeException('failed'));
            self::fail('the wrapper took a position that names no operation');
        } catch (InvalidArgumentException $refusal) {
            self::assertStringContainsString((string) $position, $refusal->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function positionsThatNameNoOperation(): iterable
    {
        yield 'the position before the first' => [-1];
        yield 'a position far below zero' => [-17];
        yield 'the smallest integer' => [\PHP_INT_MIN];
    }

    public function testTheWrapperIsInternalAndFinal(): void
    {
        $wrapper = new ReflectionClass(OperationFailedException::class);

        self::assertStringContainsString('@internal', (string) $wrapper->getDocComment());
        self::assertTrue($wrapper->isFinal());
    }
}
