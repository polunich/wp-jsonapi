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

namespace Polunich\WpJsonApi\Routing;

use InvalidArgumentException;
use LogicException;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the kind of route a request was dispatched to, and the layout of its path:
 * the one definition of which members a route addresses and which segments its path has,
 * so no two places that write a route path can drift apart.
 * every route of a type lies below the path of the type, `<path>` in the layouts below;
 * an atomic route lies at a path of its own, so its kind has no layout
 *
 * @internal
 */
enum RouteKind: string
{
    /** the literal segment that tells the relationship route from the related one */
    private const RELATIONSHIPS = 'relationships';

    /** `<path>` */
    case Collection = 'collection';

    /** `<path>/<id>` */
    case Resource = 'resource';

    /** `<path>/<id>/<relationship>` */
    case Related = 'related';

    /** `<path>/<id>/relationships/<relationship>` */
    case Relationship = 'relationship';

    /** a route of the Atomic Operations extension, at a declared path */
    case Atomic = 'atomic';

    /**
     * an atomic route addresses no type: each atomic operation names its own
     */
    public function addressesType(): bool
    {
        return $this !== self::Atomic;
    }

    public function addressesId(): bool
    {
        return $this !== self::Collection && $this !== self::Atomic;
    }

    public function addressesRelationship(): bool
    {
        return $this === self::Related || $this === self::Relationship;
    }

    /**
     * @throws InvalidArgumentException when an addressed member is missing or empty,
     *                                  or an unaddressed one is given
     */
    public function checkMembers(?string $type, ?string $id, ?string $relationship): void
    {
        $this->member('type', $type, $this->addressesType());
        $this->member('id', $id, $this->addressesId());
        $this->member('relationship', $relationship, $this->addressesRelationship());
    }

    /**
     * the path segments below the namespace. members are used as given:
     * the caller percent-encodes them for a URL,
     * or passes the parameter segment of a template as the id
     *
     * @template TId of string|object = string
     *
     * @param ?list<string> $path the segments of the path of the type
     * @param ?TId          $id
     *
     * @return non-empty-list<string|TId>
     *
     * @throws InvalidArgumentException when an addressed member is missing or empty,
     *                                  or an unaddressed one is given
     * @throws LogicException           for the atomic kind, whose path is declared
     */
    public function segments(?array $path, string|object|null $id, ?string $relationship): array
    {
        if (!$this->addressesType() && $path !== null) {
            throw new InvalidArgumentException(
                'the ' . $this->value . ' route addresses no type, got the path "/'
                . Printable::of(implode('/', $path)) . '"',
            );
        }

        $id = $this->member('id', $id, $this->addressesId());
        $relationship = $this->member('relationship', $relationship, $this->addressesRelationship());

        return match ($this) {
            self::Collection => $this->typePath($path),
            self::Resource => [...$this->typePath($path), $id],
            self::Related => [...$this->typePath($path), $id, $relationship],
            self::Relationship => [...$this->typePath($path), $id, self::RELATIONSHIPS, $relationship],
            self::Atomic => throw new LogicException(
                'an atomic route lies at the path its declaration writes, which no layout of a kind gives',
            ),
        };
    }

    /**
     * @param ?list<string> $path
     *
     * @return non-empty-list<string>
     *
     * @throws InvalidArgumentException when the path is missing or empty
     */
    private function typePath(?array $path): array
    {
        if ($path === null || $path === []) {
            throw new InvalidArgumentException(
                'the ' . $this->value . ' route needs the path of its type, which is missing',
            );
        }

        return $path;
    }

    /**
     * @template TValue of string|object
     *
     * @param ?TValue $value
     *
     * @return TValue|string
     *
     * @throws InvalidArgumentException when an addressed member is missing or empty,
     *                                  or an unaddressed one is given
     */
    private function member(string $name, string|object|null $value, bool $addressed): string|object
    {
        if ($addressed && ($value === null || $value === '')) {
            throw new InvalidArgumentException('the ' . $this->value . ' route needs ' . $name . ', which is missing');
        }

        if (!$addressed && $value !== null) {
            throw new InvalidArgumentException(
                'the ' . $this->value . ' route addresses no ' . $name . ', got "'
                . (is_string($value) ? Printable::of($value) : get_debug_type($value)) . '"',
            );
        }

        return $value ?? '';
    }
}
