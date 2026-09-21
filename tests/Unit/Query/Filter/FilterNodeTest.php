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

namespace Polunich\WpJsonApi\Tests\Unit\Query\Filter;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Filter\AllOf;
use Polunich\WpJsonApi\Query\Filter\AnyOf;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Filter\Not;
use Polunich\WpJsonApi\Query\Filter\RelationshipCondition;
use Polunich\WpJsonApi\Query\Filter\Visitor;
use ReflectionClass;
use ReflectionMethod;

/**
 * @internal
 */
#[CoversClass(FilterNode::class)]
final class FilterNodeTest extends TestCase
{
    #[DataProvider('nodes')]
    public function testAcceptDispatchesToTheMethodOfTheNode(FilterNode $node, string $expected): void
    {
        self::assertSame($expected, $node->accept(self::names()));
    }

    /**
     * @return iterable<string, array{FilterNode, string}>
     */
    public static function nodes(): iterable
    {
        $condition = new Condition(new FieldPath(['city']), '@eq', 'Kyiv');

        yield 'a condition' => [$condition, 'condition'];
        yield '@and' => [new AllOf([$condition]), 'allOf'];
        yield '@or' => [new AnyOf([$condition]), 'anyOf'];
        yield '@not' => [new Not($condition), 'not'];
        yield 'a relationship' => [new RelationshipCondition('owner', $condition), 'relationshipCondition'];
    }

    /**
     * a `Visitor` method that no case of `nodes()` dispatches to fails here
     */
    public function testEveryNodeOfTheModuleIsDispatched(): void
    {
        $dispatched = array_map(
            static function (array $case): string {
                return $case[1];
            },
            iterator_to_array(self::nodes()),
        );

        $methods = array_map(
            static function (ReflectionMethod $method): string {
                return $method->getName();
            },
            (new ReflectionClass(Visitor::class))->getMethods(),
        );

        sort($dispatched);
        sort($methods);

        self::assertSame($methods, $dispatched);
    }

    /**
     * @return Visitor<string>
     */
    private static function names(): Visitor
    {
        return new
            /**
             * @implements Visitor<string>
             */
            class implements Visitor {
                #[Override]
                public function condition(Condition $node): string
                {
                    return 'condition';
                }

                #[Override]
                public function allOf(AllOf $node): string
                {
                    return 'allOf';
                }

                #[Override]
                public function anyOf(AnyOf $node): string
                {
                    return 'anyOf';
                }

                #[Override]
                public function not(Not $node): string
                {
                    return 'not';
                }

                #[Override]
                public function relationshipCondition(RelationshipCondition $node): string
                {
                    return 'relationshipCondition';
                }
            };
    }
}
