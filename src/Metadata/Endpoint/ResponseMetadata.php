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

namespace Polunich\WpJsonApi\Metadata\Endpoint;

use InvalidArgumentException;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Metadata\Cardinality;

/**
 * one response an endpoint declares, resolved against the declaration:
 *
 * - `$type` is the type of the resources the response carries: the related type of a relationship,
 *   the monitor type of a 202; null for meta and for no content;
 * - `$parameter` names the bound record of a record, a related or a linkage response;
 * - `$relationship` and `$cardinality` belong to a related and a linkage response;
 * - `$codec` is the codec of a meta response
 *
 * @internal
 */
final readonly class ResponseMetadata
{
    /**
     * @param ?Codec<mixed> $codec
     *
     * @throws InvalidArgumentException when a member the kind needs is missing,
     *                                  or one it does not take is given
     */
    public function __construct(
        public ResponseShapeKind $kind,
        public ?string $type,
        public ?string $parameter,
        public ?string $relationship,
        public ?Cardinality $cardinality,
        public ?Codec $codec,
    ) {
        $needsType = $kind !== ResponseShapeKind::Meta && $kind !== ResponseShapeKind::NoContent;
        $needsParameter = in_array(
            $kind,
            [ResponseShapeKind::Record, ResponseShapeKind::Related, ResponseShapeKind::Linkage],
            true,
        );
        $needsRelationship = $kind === ResponseShapeKind::Related || $kind === ResponseShapeKind::Linkage;

        if (
            ($type !== null) !== $needsType
            || ($parameter !== null) !== $needsParameter
            || ($relationship !== null) !== $needsRelationship
            || ($cardinality !== null) !== $needsRelationship
            || ($codec !== null) !== ($kind === ResponseShapeKind::Meta)
        ) {
            throw new InvalidArgumentException(sprintf(
                '%s names %s, and it names exactly those',
                $kind->phrase(),
                self::membersOf($needsType, $needsParameter, $needsRelationship, $kind === ResponseShapeKind::Meta),
            ));
        }
    }

    public function status(): int
    {
        return $this->kind->status();
    }

    /**
     * whether the response carries a list:
     * a collection or a page, or the related resources or the linkage of a to-many relationship
     */
    public function isList(): bool
    {
        return $this->kind === ResponseShapeKind::Collection
            || $this->kind === ResponseShapeKind::Page
            || $this->cardinality === Cardinality::ToMany;
    }

    private static function membersOf(
        bool $needsType,
        bool $needsParameter,
        bool $needsRelationship,
        bool $needsCodec,
    ): string {
        $members = array_keys(array_filter([
            'a type' => $needsType,
            'a bound parameter' => $needsParameter,
            'a relationship with its cardinality' => $needsRelationship,
            'a codec of its meta' => $needsCodec,
        ]));

        return $members === [] ? 'nothing' : implode(', ', $members);
    }
}
