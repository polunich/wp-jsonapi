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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Operation\OperationKind;
use ReflectionEnum;

/**
 * @internal
 */
#[CoversClass(RouteOwner::class)]
final class RouteOwnerTest extends TestCase
{
    public function testTheEnumIsInternal(): void
    {
        self::assertStringContainsString(
            '@internal',
            (string) (new ReflectionEnum(RouteOwner::class))->getDocComment(),
        );
    }

    public function testATypeNamesTheFiveRoutesOfItsResources(): void
    {
        self::assertSame(
            [
                OperationKind::FetchCollection,
                OperationKind::FetchResource,
                OperationKind::CreateResource,
                OperationKind::UpdateResource,
                OperationKind::DeleteResource,
            ],
            RouteOwner::Resource->kinds(),
        );
    }

    public function testARelationshipNamesItsTwoReads(): void
    {
        self::assertSame(
            [OperationKind::FetchRelated, OperationKind::FetchRelationship],
            RouteOwner::Relationship->kinds(),
        );
    }

    public function testEachOwnerSaysWhereTheOtherKindsAreDeclared(): void
    {
        self::assertStringContainsString('replaceable(), addable() or removable()', RouteOwner::Resource->elsewhere());
        self::assertStringContainsString('its only() or except()', RouteOwner::Resource->elsewhere());
        self::assertStringContainsString(
            'replaceable(), addable() or removable()',
            RouteOwner::Relationship->elsewhere(),
        );
        self::assertStringContainsString('the only() or except() of their type', RouteOwner::Relationship->elsewhere());
    }
}
