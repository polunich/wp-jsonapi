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

use OutOfBoundsException;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Error\BadRequestException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\FilterMember;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Filter\AllOf;
use Polunich\WpJsonApi\Query\Filter\AnyOf;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Filter\Not;
use Polunich\WpJsonApi\Query\Filter\Operators;
use Polunich\WpJsonApi\Query\Filter\RelationshipCondition;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Polunich\WpJsonApi\Query\QueryText;

/**
 * a key is `@and`, `@or`, `@not`, a field, the id or a relationship.
 * an operator is looked up by the name `Metadata\ApiMetadata::operatorName()` returns for it,
 * and the node carries the operator's own name: `@notIn` under either naming policy.
 * a value is read from its query text by `QueryText`, then decoded by its operator's value codec,
 * built on the attribute's codec, so a filter reads a value as a document does.
 * a mistake is refused with 400 and never becomes a node: the nodes refuse an impossible value
 * with `InvalidArgumentException`, which is no HTTP error
 *
 * @internal
 */
final readonly class FilterParser
{
    public function __construct(private ApiMetadata $api) {}

    /**
     * null when there is no filter, and when any part of it is refused:
     * a tree with a member dropped would select more records than the client asked for
     *
     * @param mixed  $filter text, or arrays of text where the client wrote brackets
     * @param string $type   the type whose records are filtered;
     *                       its include depth caps the relationships a path runs through
     *
     * @throws OutOfBoundsException when the type, or a type a path runs through, is not declared
     */
    public function parse(mixed $filter, string $type, ErrorList $errors): ?FilterNode
    {
        if ($filter === null) {
            return null;
        }

        return $this->node(
            $filter,
            $type,
            ParameterFamily::Filter->value,
            $this->api->resources->get($type)->maxIncludeDepth,
            $errors,
        );
    }

    /**
     * the members of a filter object are a conjunction; a single member stands for itself
     *
     * @param string $parameter the parameter name as the client wrote it, up to this object
     * @param int    $depth     how many more relationships a path may run through from here
     *
     * @throws OutOfBoundsException when a type a path runs through is not declared
     */
    private function node(mixed $value, string $type, string $parameter, int $depth, ErrorList $errors): ?FilterNode
    {
        if (!is_array($value) || $value === []) {
            self::refuse($errors, ErrorCode::InvalidQueryParameter, ['name' => $parameter], $parameter);

            return null;
        }

        $nodes = [];
        $refused = false;

        foreach ($value as $key => $child) {
            $name = (string) $key;
            $node = $this->member($name, $child, $type, $parameter . '[' . $name . ']', $depth, $errors);

            if ($node === null) {
                $refused = true;

                continue;
            }

            $nodes[] = $node;
        }

        if ($refused) {
            return null;
        }

        return count($nodes) === 1 ? $nodes[0] : new AllOf($nodes);
    }

    /**
     * @param string $parameter the parameter name as the client wrote it, this member included
     *
     * @throws OutOfBoundsException when a type a path runs through is not declared
     */
    private function member(
        string $name,
        mixed $child,
        string $type,
        string $parameter,
        int $depth,
        ErrorList $errors,
    ): ?FilterNode {
        if ($name === Operators::ALL_OF) {
            $nodes = $this->group($child, $type, $parameter, $depth, $errors);

            return $nodes === null ? null : new AllOf($nodes);
        }

        if ($name === Operators::ANY_OF) {
            $nodes = $this->group($child, $type, $parameter, $depth, $errors);

            return $nodes === null ? null : new AnyOf($nodes);
        }

        if ($name === Operators::NOT) {
            $node = $this->node($child, $type, $parameter, $depth, $errors);

            return $node === null ? null : new Not($node);
        }

        return $this->field($name, $child, $type, $parameter, $depth, $errors);
    }

    /**
     * @return ?list<FilterNode>
     *
     * @throws OutOfBoundsException when a type a path runs through is not declared
     */
    private function group(mixed $value, string $type, string $parameter, int $depth, ErrorList $errors): ?array
    {
        if (!is_array($value) || $value === []) {
            self::refuse($errors, ErrorCode::InvalidQueryParameter, ['name' => $parameter], $parameter);

            return null;
        }

        $nodes = [];
        $refused = false;

        foreach ($value as $key => $child) {
            $node = $this->node($child, $type, $parameter . '[' . (string) $key . ']', $depth, $errors);

            if ($node === null) {
                $refused = true;

                continue;
            }

            $nodes[] = $node;
        }

        return $refused ? null : $nodes;
    }

    /**
     * a field or the id reads its conditions;
     * a relationship reads a filter object of its related type one relationship deeper.
     * a dotted key names no member: a condition through a relationship is written nested
     *
     * @throws OutOfBoundsException when the type or the related type is not declared
     */
    private function field(
        string $name,
        mixed $child,
        string $type,
        string $parameter,
        int $depth,
        ErrorList $errors,
    ): ?FilterNode {
        $member = $this->api->resources->filterMember($type, $name);
        $relationship = $member?->relationship;

        if ($member === null || ($relationship !== null && $depth === 0)) {
            self::refuse($errors, ErrorCode::UnsupportedFilterField, ['type' => $type, 'field' => $name], $parameter);

            return null;
        }

        if ($relationship === null) {
            return $this->conditions($child, $member, $parameter, $errors);
        }

        $condition = $this->node($child, $relationship->relatedType, $parameter, $depth - 1, $errors);

        return $condition === null ? null : new RelationshipCondition($relationship->name, $condition);
    }

    /**
     * `@not` is admitted inside a field as well;
     * the comparisons are a conjunction, like the members of a filter object
     *
     * @param FilterMember $member the attribute or the id the conditions compare
     */
    private function conditions(
        mixed $value,
        FilterMember $member,
        string $parameter,
        ErrorList $errors,
    ): ?FilterNode {
        if (!is_array($value) || $value === []) {
            self::refuse($errors, ErrorCode::InvalidQueryParameter, ['name' => $parameter], $parameter);

            return null;
        }

        $nodes = [];
        $refused = false;

        foreach ($value as $key => $operand) {
            $name = (string) $key;
            $child = $parameter . '[' . $name . ']';

            if ($name === Operators::NOT) {
                $node = $this->conditions($operand, $member, $child, $errors);
                $node = $node === null ? null : new Not($node);
            } else {
                $node = $this->condition($operand, $member, $name, $child, $errors);
            }

            if ($node === null) {
                $refused = true;

                continue;
            }

            $nodes[] = $node;
        }

        if ($refused) {
            return null;
        }

        return count($nodes) === 1 ? $nodes[0] : new AllOf($nodes);
    }

    /**
     * @param string $name the operator as the client wrote it
     */
    private function condition(
        mixed $operand,
        FilterMember $member,
        string $name,
        string $parameter,
        ErrorList $errors,
    ): ?Condition {
        $operator = $this->api->operatorWritten($name);

        if ($operator === null || !in_array($operator->name, $member->operators(), true)) {
            self::refuse(
                $errors,
                ErrorCode::UnsupportedFilterOperator,
                ['field' => $member->name, 'operator' => $name],
                $parameter,
            );

            return null;
        }

        $codec = $operator->valueCodec($member->codec());

        try {
            $value = $codec->decode(QueryText::toWire($operand, $codec->schema()), Pointer::root());
        } catch (DecodeException $exception) {
            foreach ($exception->violations() as $violation) {
                self::refuse(
                    $errors,
                    $violation->code,
                    $violation->parameters,
                    $parameter . QueryText::brackets($violation->pointer),
                );
            }

            return null;
        }

        return new Condition(new FieldPath([$member->name]), $operator->name, $value);
    }

    /**
     * @param string|ErrorCode      $code       a library code, or the code of a codec violation
     * @param array<string, scalar> $parameters
     * @param string                $parameter  as the client wrote it
     */
    private static function refuse(
        ErrorList $errors,
        string|ErrorCode $code,
        array $parameters,
        string $parameter,
    ): void {
        $errors->add(new BadRequestException([
            new ErrorDescription($code, $parameters, parameter: $parameter),
        ]));
    }
}
