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
use ReflectionMethod;

/**
 * @internal
 */
#[CoversClass(Visitor::class)]
final class VisitorTest extends TestCase
{
    /**
     * every project implements these methods, so a changed signature breaks each of them
     *
     * @param array{string, string} $parameter the name and the type of the single parameter
     */
    #[DataProvider('signatures')]
    public function testTheInterfaceDeclaresTheSignatureOfItsMethod(string $method, array $parameter): void
    {
        $reflection = new ReflectionMethod(Visitor::class, $method);
        $parameters = $reflection->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame($parameter[0], $parameters[0]->getName());
        self::assertSame($parameter[1], (string) $parameters[0]->getType());
        self::assertSame('mixed', (string) $reflection->getReturnType());
    }

    /**
     * @return iterable<string, array{string, array{string, string}}>
     */
    public static function signatures(): iterable
    {
        yield 'condition' => ['condition', ['node', Condition::class]];
        yield 'allOf' => ['allOf', ['node', AllOf::class]];
        yield 'anyOf' => ['anyOf', ['node', AnyOf::class]];
        yield 'not' => ['not', ['node', Not::class]];
        yield 'relationshipCondition' => ['relationshipCondition', ['node', RelationshipCondition::class]];
    }

    /**
     * a child's result reaches its parent through `accept()`
     */
    public function testWalksTheWholeTree(): void
    {
        $tree = new Not(new AnyOf([
            new Condition(new FieldPath(['city']), '@eq', 'Kyiv'),
            new RelationshipCondition(
                'owner',
                new Condition(new FieldPath(['name']), '@contains', 'a'),
            ),
        ]));

        self::assertSame(
            'not(or(city @eq "Kyiv", owner(name @contains "a")))',
            $tree->accept(self::text()),
        );
    }

    /**
     * @return Visitor<string>
     */
    private static function text(): Visitor
    {
        return new
            /**
             * @implements Visitor<string>
             */
            class implements Visitor {
                #[Override]
                public function condition(Condition $node): string
                {
                    return sprintf(
                        '%s %s %s',
                        $node->field->toString(),
                        $node->operator,
                        json_encode($node->value, JSON_THROW_ON_ERROR),
                    );
                }

                #[Override]
                public function allOf(AllOf $node): string
                {
                    return 'and(' . $this->children($node->nodes) . ')';
                }

                #[Override]
                public function anyOf(AnyOf $node): string
                {
                    return 'or(' . $this->children($node->nodes) . ')';
                }

                #[Override]
                public function not(Not $node): string
                {
                    return 'not(' . $node->node->accept($this) . ')';
                }

                #[Override]
                public function relationshipCondition(RelationshipCondition $node): string
                {
                    return $node->relationship . '(' . $node->condition->accept($this) . ')';
                }

                /**
                 * @param non-empty-list<FilterNode> $nodes
                 */
                private function children(array $nodes): string
                {
                    return implode(', ', array_map(
                        function (FilterNode $node): string {
                            return $node->accept($this);
                        },
                        $nodes,
                    ));
                }
            };
    }
}
