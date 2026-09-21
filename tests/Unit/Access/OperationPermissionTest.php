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

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Tests\Fixtures\Access\ClosurePermission;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use stdClass;

/**
 * `CoversClass` on an interface warns once coverage is collected:
 * php-code-coverage maps no interface as a class target
 *
 * @internal
 */
#[CoversNothing]
final class OperationPermissionTest extends TestCase
{
    /**
     * the parameter name is public API: PHP matches named arguments against it
     */
    public function testThePortDeclaresOneMethod(): void
    {
        $port = new ReflectionClass(OperationPermission::class);
        $method = new ReflectionMethod(OperationPermission::class, 'allows');
        $parameter = $method->getParameters()[0] ?? null;

        self::assertTrue($port->isInterface());
        self::assertSame(
            ['allows'],
            array_map(static function (ReflectionMethod $declared): string {
                return $declared->getName();
            }, $port->getMethods()),
        );
        self::assertInstanceOf(ReflectionParameter::class, $parameter);
        self::assertSame('attempt', $parameter->getName());
        self::assertSame(OperationAttempt::class, (string) $parameter->getType());
        self::assertCount(1, $method->getParameters());
        self::assertSame('bool', (string) $method->getReturnType());
    }

    public function testThePortIsPublicApiAndLivesInAccess(): void
    {
        $port = new ReflectionClass(OperationPermission::class);

        self::assertDoesNotMatchRegularExpression('/^\s*\*\s*@internal\b/m', (string) $port->getDocComment());
        self::assertSame('Polunich\WpJsonApi\Access', $port->getNamespaceName());
    }

    #[DataProvider('results')]
    public function testTheResultIsTrueToGrantAndFalseToDeny(bool $result): void
    {
        $permission = new ClosurePermission(static function (OperationAttempt $attempt) use ($result): bool {
            return $result;
        });

        self::assertSame($result, $permission->allows(self::attempt(OperationKind::FetchResource)));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function results(): iterable
    {
        yield 'a permission that grants' => [true];
        yield 'a permission that denies' => [false];
    }

    public function testAnImplementationTellsTheTwoLevelsApartByTheRecord(): void
    {
        $seen = [];
        $permission = new ClosurePermission(static function (OperationAttempt $attempt) use (&$seen): bool {
            $seen[] = $attempt->record === null ? 'level 1' : 'level 2';

            return true;
        });
        $request = new ServerRequest('PATCH', new Headers(), [], [], '');

        $permission->allows(OperationAttempt::forKind(OperationKind::UpdateResource, 'pets', null, $request));
        $permission->allows(OperationAttempt::forRecord(
            OperationKind::UpdateResource,
            'pets',
            null,
            $request,
            new stdClass(),
        ));

        self::assertSame(['level 1', 'level 2'], $seen);
    }

    public function testTheAttemptCarriesWhatThePermissionDecidesOn(): void
    {
        $request = new ServerRequest('POST', new Headers(['X-Role' => 'editor']), [], [], '');
        $permission = new ClosurePermission(static function (OperationAttempt $attempt): bool {
            return $attempt->kind === OperationKind::AddToRelationship
                && $attempt->type === 'pets'
                && $attempt->relationship === 'orders'
                && $attempt->request->headers->line('X-Role') === 'editor';
        });

        self::assertTrue($permission->allows(
            OperationAttempt::forKind(OperationKind::AddToRelationship, 'pets', 'orders', $request),
        ));
    }

    private static function attempt(OperationKind $kind): OperationAttempt
    {
        return OperationAttempt::forKind($kind, 'pets', null, new ServerRequest('GET', new Headers(), [], [], ''));
    }
}
