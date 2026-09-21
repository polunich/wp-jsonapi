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

namespace Polunich\WpJsonApi\Tests\Unit\Metadata;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\OwnRouteKind;
use Polunich\WpJsonApi\Operation\OperationKind;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(OwnRouteKind::class)]
final class OwnRouteKindTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(OwnRouteKind::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testAKindOfTheOwnRoutesThatIsEnabledPasses(): void
    {
        OwnRouteKind::checkOwned(OperationKind::FetchResource, [OperationKind::FetchResource], 'type "pets"', 'x', 'y');
        OwnRouteKind::checkEnabled(OperationKind::FetchResource, static function (): bool {
            return true;
        }, 'type "pets"', 'x');

        $this->addToAssertionCount(1);
    }

    public function testAKindOfAnotherOwnersRoutesIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'type "pets" declares security schemes for fetch_related, which is no route of its own: a relationship'
            . ' declares them',
        );

        OwnRouteKind::checkOwned(
            OperationKind::FetchRelated,
            [OperationKind::FetchResource],
            'type "pets"',
            'security schemes',
            'a relationship declares them',
        );
    }

    public function testAKindTheDeclarationDoesNotEnableIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares security schemes for delete_resource, which it does not'
            . ' enable');

        OwnRouteKind::checkEnabled(OperationKind::DeleteResource, static function (): bool {
            return false;
        }, 'type "pets"', 'security schemes');
    }
}
