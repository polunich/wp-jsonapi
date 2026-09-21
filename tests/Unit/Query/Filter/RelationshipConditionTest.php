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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\RelationshipCondition;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(RelationshipCondition::class)]
final class RelationshipConditionTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(RelationshipCondition::class))->isReadOnly());
    }

    public function testCarriesTheRelationshipAndTheConditionOnTheRelatedType(): void
    {
        $condition = new Condition(new FieldPath(['name']), '@eq', 'Ada');
        $node = new RelationshipCondition('owner', $condition);

        self::assertSame('owner', $node->relationship);
        self::assertSame($condition, $node->condition);
    }

    public function testNestsOneNodePerRelationshipOfAPath(): void
    {
        $inner = new RelationshipCondition(
            'supplier',
            new Condition(new FieldPath(['name']), '@eq', 'Polunich'),
        );
        $outer = new RelationshipCondition('owner', $inner);

        self::assertSame($inner, $outer->condition);
    }

    #[DataProvider('illegalNames')]
    public function testRefusesARelationshipThatIsNoMemberName(string $relationship): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is no member name');

        new RelationshipCondition(
            $relationship,
            new Condition(new FieldPath(['name']), '@eq', 'Ada'),
        );
    }

    public function testEscapesAControlCharacterOfARefusedRelationshipInTheMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "own\ner" is no member name, so no relationship carries it');

        new RelationshipCondition("own\ner", new Condition(new FieldPath(['name']), '@eq', 'Ada'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function illegalNames(): iterable
    {
        yield 'empty' => [''];
        yield 'a path instead of a name' => ['owner.supplier'];
        yield 'a reserved character' => ['owner,supplier'];
        yield 'an underscore first' => ['_owner'];
    }
}
