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

namespace Polunich\WpJsonApi\Tests\Unit\Access;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\EndpointAttempt;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

/**
 * @internal
 */
#[CoversClass(EndpointAttempt::class)]
final class EndpointAttemptTest extends TestCase
{
    public function testTheClassIsAPublicFinalReadonlyValueBuiltByTheTwoLevels(): void
    {
        $class = new ReflectionClass(EndpointAttempt::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertTrue($class->getConstructor()?->isPrivate());
        self::assertDoesNotMatchRegularExpression('/@internal\b/', (string) $class->getDocComment());
        self::assertSame(
            ['forEndpoint', 'forRecords'],
            array_map(static function (ReflectionMethod $method): string {
                return $method->getName();
            }, $class->getMethods(ReflectionMethod::IS_PUBLIC)),
        );
        self::assertSame(
            [['endpointName', 'string'], ['request', ServerRequest::class], ['records', '?array']],
            array_map(static function (ReflectionProperty $property): array {
                return [$property->getName(), (string) $property->getType()];
            }, $class->getProperties(ReflectionProperty::IS_PUBLIC)),
        );
    }

    public function testTheFirstLevelCarriesNoRecords(): void
    {
        $request = self::request();

        $attempt = EndpointAttempt::forEndpoint('adoptPet', $request);

        self::assertSame('adoptPet', $attempt->endpointName);
        self::assertSame($request, $attempt->request);
        self::assertNull($attempt->records);
    }

    public function testTheSecondLevelCarriesTheBoundRecords(): void
    {
        $pet = new stdClass();

        $attempt = EndpointAttempt::forRecords('adoptPet', self::request(), ['id' => $pet]);

        self::assertSame(['id' => $pet], $attempt->records);
    }

    public function testAnEndpointIsNamed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an attempted endpoint is named, and a name is never empty');

        EndpointAttempt::forEndpoint('', self::request());
    }

    private static function request(): ServerRequest
    {
        return new ServerRequest('POST', new Headers(), [], ['id' => '1'], '');
    }
}
