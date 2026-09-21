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

namespace Polunich\WpJsonApi\Parsing;

use InvalidArgumentException;
use OutOfBoundsException;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Error\BadRequestException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Filter\AllOf;
use Polunich\WpJsonApi\Query\Filter\AnyOf;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Filter\Operators;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\PageParameter;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Polunich\WpJsonApi\Query\Sort\Direction;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Throwable;

/**
 * turns the page cursors into filter conditions, the seek method.
 * with sort fields f1..fn and boundary values v1..vn, the records after the cursor are
 *
 *   f1 > v1, or f1 = v1 and f2 > v2, or ... or f1 = v1 and ... and fn > vn
 *
 * with `>` read in the direction of each field and turned around for `page[before]`.
 * the sort holds `id`, so the chain orders the records totally;
 * a range, both cursors at once, takes both chains.
 * reversing the sort for a lone `page[before]` is the reading's part, not this class's.
 *
 * a cursor carries each sort value in wire form, and the codec of its field decodes it,
 * so a cursor condition is a filter condition like any other.
 * a cursor is opaque to the client, so one whose fields differ from the sort,
 * or whose value a codec refuses, belongs to another request:
 * 400 `invalid_query_parameter`, the cursor profile's response to an invalid cursor.
 * a sort path the registry refuses, or a sort without `id`, was checked before
 * and is a library mistake: `InvalidArgumentException`
 *
 * @internal
 */
final readonly class CursorConditions
{
    public function __construct(
        private ResourceRegistry $resources,
        private NamingPolicy $naming,
    ) {}

    /**
     * null when the page carries no cursor, or when every cursor it carries was refused
     *
     * @param string $type the type of the records the cursors name
     * @param Sort   $sort the sort the reading runs with, `id` included
     *
     * @throws InvalidArgumentException when the page is of another strategy, the sort lacks `id`,
     *                                  or a sort field is one the type does not declare
     */
    public function of(PageRequest $page, string $type, Sort $sort, ErrorList $errors): ?FilterNode
    {
        self::check($page, $sort);

        $chains = [];

        if ($page->after !== null) {
            $chains[] = $this->chain(
                $page->after,
                $type,
                $sort,
                true,
                $this->parameter(PageParameter::After),
                $errors,
            );
        }

        if ($page->before !== null) {
            $chains[] = $this->chain(
                $page->before,
                $type,
                $sort,
                false,
                $this->parameter(PageParameter::Before),
                $errors,
            );
        }

        $chains = array_values(array_filter($chains, static function (?FilterNode $chain): bool {
            return $chain !== null;
        }));

        return $chains === [] ? null : self::all($chains);
    }

    private function chain(
        Cursor $cursor,
        string $type,
        Sort $sort,
        bool $after,
        string $parameter,
        ErrorList $errors,
    ): ?FilterNode {
        $values = $this->values($cursor, $type, $sort, $parameter, $errors);

        if ($values === null) {
            return null;
        }

        $branches = [];
        $equal = [];

        foreach ($sort->fields() as $index => $field) {
            $value = $values[$index];
            $branches[] = self::all([
                ...$equal,
                new Condition($field->field, self::comparison($field->direction, $after), $value),
            ]);
            $equal[] = new Condition($field->field, Operators::EQUAL, $value);
        }

        return self::any($branches);
    }

    /**
     * @return list<mixed>|null
     *
     * @throws InvalidArgumentException when a sort field is one the type does not declare
     */
    private function values(Cursor $cursor, string $type, Sort $sort, string $parameter, ErrorList $errors): ?array
    {
        $carried = [];

        foreach ($cursor->sortValues as $field => $value) {
            $carried[(string) $field] = $value;
        }

        $values = [];

        foreach ($sort->fields() as $field) {
            $written = $field->field->toString();

            // a cursor carries `id` in a member of its own, not among the sort values
            if ($field->field->isId()) {
                $wire = $cursor->id;
            } elseif (array_key_exists($written, $carried)) {
                $wire = $carried[$written];
                unset($carried[$written]);
            } else {
                $errors->add(self::invalid($parameter));

                return null;
            }

            try {
                $values[] = $this->codecOf($type, $field->field)->decode($wire, Pointer::root());
            } catch (DecodeException $refusal) {
                $errors->add(self::invalid($parameter, $refusal));

                return null;
            }
        }

        if ($carried !== []) {
            $errors->add(self::invalid($parameter));

            return null;
        }

        return $values;
    }

    /**
     * @return Codec<mixed>
     *
     * @throws InvalidArgumentException when the registry refuses the path
     * @throws OutOfBoundsException     when the type is not declared
     */
    private function codecOf(string $type, FieldPath $path): Codec
    {
        return $this->resources->sortPath($type, $path)->codec();
    }

    /**
     * the parameter as the client writes it, such as `page[after]`
     */
    private function parameter(PageParameter $member): string
    {
        return ParameterFamily::Page->parameter($member->nameIn($this->naming));
    }

    private static function comparison(Direction $direction, bool $after): string
    {
        if ($direction === Direction::Ascending) {
            return $after ? Operators::GREATER_THAN : Operators::LESS_THAN;
        }

        return $after ? Operators::LESS_THAN : Operators::GREATER_THAN;
    }

    /**
     * @param list<FilterNode> $nodes
     *
     * @throws InvalidArgumentException when the list is empty
     */
    private static function all(array $nodes): FilterNode
    {
        return count($nodes) === 1 ? $nodes[0] : new AllOf($nodes);
    }

    /**
     * @param list<FilterNode> $nodes
     *
     * @throws InvalidArgumentException when the list is empty
     */
    private static function any(array $nodes): FilterNode
    {
        return count($nodes) === 1 ? $nodes[0] : new AnyOf($nodes);
    }

    /**
     * @throws InvalidArgumentException when the page is of another strategy, or the sort lacks `id`
     */
    private static function check(PageRequest $page, Sort $sort): void
    {
        if ($page->strategy !== Strategy::Cursor) {
            throw new InvalidArgumentException(sprintf(
                'the page parameters select the %s strategy, and a cursor is translated for the cursor strategy'
                . ' alone',
                $page->strategy->value,
            ));
        }

        if (!$sort->hasField(FieldPath::id())) {
            throw new InvalidArgumentException(sprintf(
                'sort "%s" does not hold id, and the conditions of a cursor divide the records totally only'
                . ' when it does',
                $sort->toString(),
            ));
        }
    }

    private static function invalid(string $parameter, ?Throwable $previous = null): BadRequestException
    {
        return new BadRequestException(
            [new ErrorDescription(ErrorCode::InvalidQueryParameter, ['name' => $parameter], parameter: $parameter)],
            $previous,
        );
    }
}
