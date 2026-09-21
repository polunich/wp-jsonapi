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

namespace Polunich\WpJsonApi\Tests\Unit\Error;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\UnauthorizedException;
use ReflectionMethod;
use ReflectionParameter;

/**
 * what the challenges add to the contract `StatusExceptionsTest` runs on every status exception
 *
 * @internal
 */
#[CoversClass(UnauthorizedException::class)]
final class UnauthorizedExceptionTest extends TestCase
{
    public function testTheChallengesStandBetweenTheDescriptionsAndThePreviousExceptionAndDefaultToNone(): void
    {
        $parameters = (new ReflectionMethod(UnauthorizedException::class, '__construct'))->getParameters();
        $names = array_map(static function (ReflectionParameter $parameter): string {
            return $parameter->getName();
        }, $parameters);

        self::assertSame(['descriptions', 'challenges', 'previous'], $names);
        self::assertTrue($parameters[1]->isDefaultValueAvailable());
        self::assertSame([], $parameters[1]->getDefaultValue());
    }

    public function testTheChallengesAreKeptInTheirOrder(): void
    {
        $basic = new Challenge('Basic', ['realm' => 'WordPress']);
        $bearer = new Challenge('Bearer', ['realm' => 'example']);

        $exception = new UnauthorizedException(
            descriptions: [new ErrorDescription(ErrorCode::Unauthorized)],
            challenges: [$basic, $bearer],
        );

        self::assertSame([$basic, $bearer], $exception->challenges());
    }

    public function testAnExceptionWithoutAChallengeCarriesAnEmptyList(): void
    {
        $exception = new UnauthorizedException([new ErrorDescription('rest_not_logged_in')]);

        self::assertSame([], $exception->challenges());
    }

    /**
     * a challenge is no problem of the request
     */
    public function testTheMessageLeavesTheChallengesOut(): void
    {
        $exception = new UnauthorizedException(
            [new ErrorDescription(ErrorCode::Unauthorized)],
            [new Challenge('Basic', ['realm' => 'WordPress'])],
        );

        self::assertSame('the request failed with status 401: unauthorized', $exception->getMessage());
    }

}
