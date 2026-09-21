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

namespace Polunich\WpJsonApi\Tests\Fixtures\Api;

use Closure;
use DateTimeInterface;
use LogicException;
use Override;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Filter\AllOf;
use Polunich\WpJsonApi\Query\Filter\AnyOf;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\Not;
use Polunich\WpJsonApi\Query\Filter\RelationshipCondition;
use Polunich\WpJsonApi\Query\Filter\Visitor;

/**
 * evaluates a filter tree over in-memory records, as the visitor of a project does over its storage
 *
 * a null field fails every operator but `@null` and `@notNull`,
 * as a comparison with NULL fails in SQL.
 * a relationship condition matches a to-one relationship when its record does,
 * and a to-many one when at least one of its records does.
 * a field path through a to-one relationship, which a cursor writes for a sort through one,
 * is followed the same way
 *
 * @implements Visitor<Closure(Record): bool>
 *
 * @internal
 */
final readonly class RecordMatcher implements Visitor
{
    /**
     * @param Closure(Record, string): list<Record> $related the related records,
     *                                                       in the order of the relationship
     */
    public function __construct(private Closure $related) {}

    /**
     * null when a relationship on the way is empty
     *
     * @throws LogicException when the path runs through a to-many relationship,
     *                        which no sort or cursor names
     */
    public function value(Record $record, FieldPath $path): mixed
    {
        $current = $record;

        foreach ($path->relationships() as $relationship) {
            if (array_key_exists($relationship, $current->toMany)) {
                throw new LogicException(sprintf('the path "%s" runs through a to-many relationship', $path));
            }

            $current = ($this->related)($current, $relationship)[0] ?? null;

            if ($current === null) {
                return null;
            }
        }

        return $path->name() === 'id' ? $current->id : $current->attribute($path->name());
    }

    /**
     * @return Closure(Record): bool
     */
    #[Override]
    public function condition(Condition $node): Closure
    {
        return function (Record $record) use ($node): bool {
            return self::satisfies($node->operator, $this->value($record, $node->field), $node->value);
        };
    }

    /**
     * @return Closure(Record): bool
     */
    #[Override]
    public function allOf(AllOf $node): Closure
    {
        $predicates = array_map(function ($child): Closure {
            return $child->accept($this);
        }, $node->nodes);

        return static function (Record $record) use ($predicates): bool {
            foreach ($predicates as $predicate) {
                if (!$predicate($record)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * @return Closure(Record): bool
     */
    #[Override]
    public function anyOf(AnyOf $node): Closure
    {
        $predicates = array_map(function ($child): Closure {
            return $child->accept($this);
        }, $node->nodes);

        return static function (Record $record) use ($predicates): bool {
            foreach ($predicates as $predicate) {
                if ($predicate($record)) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * @return Closure(Record): bool
     */
    #[Override]
    public function not(Not $node): Closure
    {
        $predicate = $node->node->accept($this);

        return static function (Record $record) use ($predicate): bool {
            return !$predicate($record);
        };
    }

    /**
     * @return Closure(Record): bool
     */
    #[Override]
    public function relationshipCondition(RelationshipCondition $node): Closure
    {
        $predicate = $node->condition->accept($this);

        return function (Record $record) use ($node, $predicate): bool {
            foreach (($this->related)($record, $node->relationship) as $related) {
                if ($predicate($related)) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * strings compare by their bytes, not by a collation; null sorts before every other value
     */
    public static function compareValues(mixed $first, mixed $second): int
    {
        if ($first === null || $second === null) {
            return ($first === null ? 0 : 1) - ($second === null ? 0 : 1);
        }

        if ($first instanceof DateTimeInterface && $second instanceof DateTimeInterface) {
            return $first <=> $second;
        }

        if (is_string($first) && is_string($second)) {
            return strcmp($first, $second) <=> 0;
        }

        if ((is_int($first) || is_float($first)) && (is_int($second) || is_float($second))) {
            return $first <=> $second;
        }

        if (is_bool($first) && is_bool($second)) {
            return $first <=> $second;
        }

        throw new LogicException(sprintf(
            'a %s is not compared with a %s',
            get_debug_type($first),
            get_debug_type($second),
        ));
    }

    private static function satisfies(string $operator, mixed $field, mixed $value): bool
    {
        if ($operator === '@null' || $operator === '@notNull') {
            return ($field === null) === (($operator === '@null') === $value);
        }

        if ($field === null) {
            return false;
        }

        return match ($operator) {
            '@eq' => self::compareValues($field, $value) === 0,
            '@ne' => self::compareValues($field, $value) !== 0,
            '@lt' => self::compareValues($field, $value) < 0,
            '@lte' => self::compareValues($field, $value) <= 0,
            '@gt' => self::compareValues($field, $value) > 0,
            '@gte' => self::compareValues($field, $value) >= 0,
            '@in' => self::among($field, $value),
            '@notIn' => !self::among($field, $value),
            '@between' => is_array($value) && count($value) === 2
                && self::compareValues($field, $value[0] ?? null) >= 0
                    && self::compareValues($field, $value[1] ?? null) <= 0,
            '@eqi' => self::fold($field) === self::fold($value),
            '@nei' => self::fold($field) !== self::fold($value),
            '@contains' => str_contains(self::text($field), self::text($value)),
            '@notContains' => !str_contains(self::text($field), self::text($value)),
            '@containsi' => str_contains(self::fold($field), self::fold($value)),
            '@notContainsi' => !str_contains(self::fold($field), self::fold($value)),
            '@startsWith' => str_starts_with(self::text($field), self::text($value)),
            '@startsWithi' => str_starts_with(self::fold($field), self::fold($value)),
            '@endsWith' => str_ends_with(self::text($field), self::text($value)),
            '@endsWithi' => str_ends_with(self::fold($field), self::fold($value)),
            default => throw new LogicException(sprintf('the fixture store knows no operator "%s"', $operator)),
        };
    }

    private static function among(mixed $field, mixed $values): bool
    {
        if (!is_array($values)) {
            throw new LogicException('a list operator carries a list');
        }

        foreach ($values as $value) {
            if (self::compareValues($field, $value) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new LogicException(sprintf('a text operator compares a string, not a %s', get_debug_type($value)));
        }

        return $value;
    }

    private static function fold(mixed $value): string
    {
        return mb_strtolower(self::text($value));
    }
}
