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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\AllowAny;
use Polunich\WpJsonApi\Access\EndpointAttempt;
use Polunich\WpJsonApi\Access\EndpointPermission;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Operation\OperationKind;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(AllowAny::class)]
final class AllowAnyTest extends TestCase
{
    public function testTheClassIsAPublicFinalReadonlyPermission(): void
    {
        $class = new ReflectionClass(AllowAny::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertTrue($class->implementsInterface(OperationPermission::class));
        self::assertTrue($class->implementsInterface(EndpointPermission::class));
        self::assertDoesNotMatchRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testEveryAttemptIsGranted(): void
    {
        $request = new ServerRequest('DELETE', new Headers(), [], ['id' => '1'], '');
        $permission = new AllowAny();

        self::assertTrue($permission->allows(OperationAttempt::forKind(
            OperationKind::DeleteResource,
            'pets',
            null,
            $request,
        )));
        self::assertTrue($permission->allows(OperationAttempt::forRecord(
            OperationKind::DeleteResource,
            'pets',
            null,
            $request,
            new stdClass(),
        )));
        self::assertTrue($permission->allows(EndpointAttempt::forEndpoint('adoptPet', $request)));
        self::assertTrue($permission->allows(EndpointAttempt::forRecords(
            'adoptPet',
            $request,
            ['id' => new stdClass()],
        )));
    }
}
