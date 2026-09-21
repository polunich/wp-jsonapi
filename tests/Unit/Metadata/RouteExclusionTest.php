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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Operation\OperationKind;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(RouteExclusion::class)]
final class RouteExclusionTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(RouteExclusion::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
        self::assertTrue($class->getConstructor()?->isPrivate());
    }

    public function testNoneExcludesNothing(): void
    {
        $exclusion = RouteExclusion::none(RouteOwner::Resource);

        self::assertSame(RouteOwner::Resource, $exclusion->owner);
        self::assertSame([], $exclusion->kinds);
        self::assertFalse($exclusion->excludesAll());

        foreach (OperationKind::cases() as $kind) {
            self::assertFalse($exclusion->excludes($kind), $kind->value);
        }
    }

    public function testExceptExcludesTheNamedKindsInTheOrderOfTheOwner(): void
    {
        $exclusion = RouteExclusion::except(
            RouteOwner::Resource,
            [OperationKind::DeleteResource, OperationKind::FetchCollection],
            'type "pets"',
        );

        self::assertSame([OperationKind::FetchCollection, OperationKind::DeleteResource], $exclusion->kinds);
        self::assertTrue($exclusion->excludes(OperationKind::DeleteResource));
        self::assertFalse($exclusion->excludes(OperationKind::FetchResource));
    }

    public function testOnlyExcludesEveryOtherKindOfTheOwner(): void
    {
        $exclusion = RouteExclusion::only(RouteOwner::Resource, [OperationKind::FetchResource], 'type "pets"');

        self::assertSame(
            [
                OperationKind::FetchCollection,
                OperationKind::CreateResource,
                OperationKind::UpdateResource,
                OperationKind::DeleteResource,
            ],
            $exclusion->kinds,
        );
    }

    public function testOnlyOfEveryKindExcludesNothing(): void
    {
        self::assertSame(
            [],
            RouteExclusion::only(
                RouteOwner::Relationship,
                [OperationKind::FetchRelated, OperationKind::FetchRelationship],
                'relationship "owner"',
            )->kinds,
        );
    }

    public function testExcludesAllWhereNoKindOfTheOwnerKeepsItsRoute(): void
    {
        self::assertTrue(
            RouteExclusion::except(
                RouteOwner::Relationship,
                [OperationKind::FetchRelated, OperationKind::FetchRelationship],
                'relationship "owner"',
            )->excludesAll(),
        );
        self::assertFalse(
            RouteExclusion::except(
                RouteOwner::Relationship,
                [OperationKind::FetchRelated],
                'relationship "owner"',
            )->excludesAll(),
        );
    }

    #[DataProvider('calls')]
    public function testRefusesAKindTheOwnerDoesNotName(string $call): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'relationship "owner" names update_resource in %s(), and it names only fetch_related, fetch_relationship'
            . ' there: %s',
            $call,
            RouteOwner::Relationship->elsewhere(),
        ));

        RouteExclusion::$call(RouteOwner::Relationship, [OperationKind::UpdateResource], 'relationship "owner"');
    }

    #[DataProvider('calls')]
    public function testRefusesAKindNamedTwice(string $call): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'type "pets" names fetch_resource twice in %s(), and each kind is named once',
            $call,
        ));

        RouteExclusion::$call(
            RouteOwner::Resource,
            [OperationKind::FetchResource, OperationKind::CreateResource, OperationKind::FetchResource],
            'type "pets"',
        );
    }

    /**
     * @return iterable<string, array{'except'|'only'}>
     */
    public static function calls(): iterable
    {
        yield 'only' => ['only'];
        yield 'except' => ['except'];
    }
}
