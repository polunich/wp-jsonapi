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

namespace Polunich\WpJsonApi\Query\Inclusion;

use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Document\MemberName;
use Polunich\WpJsonApi\Text\Printable;
use Stringable;

/**
 * one relationship path of `include`, `orders.customer`.
 * it starts from the primary type; on a relationship endpoint, from the parent's type,
 * so it begins with that relationship: `/pets/1/relationships/orders?include=orders.customer`.
 * `.` is reserved in member names, so it never stands inside a segment.
 *
 * whether the relationships exist, and how deep a path may run, is not checked here
 */
final readonly class IncludePath implements Stringable
{
    private const SEPARATOR = '.';

    /**
     * @var non-empty-list<string>
     */
    private array $relationships;

    /**
     * @param list<string> $relationships
     *
     * @throws InvalidArgumentException when the path names no relationship,
     *                                  or a segment is no member name
     */
    public function __construct(array $relationships)
    {
        if ($relationships === []) {
            throw new InvalidArgumentException(
                'a relationship path names at least one relationship; the client that wants no '
                . 'related resources sends the empty include parameter, which names no path at all',
            );
        }

        foreach ($relationships as $relationship) {
            if (!MemberName::isValid($relationship)) {
                throw new InvalidArgumentException(sprintf(
                    'segment "%s" of a relationship path is no member name, so no relationship carries it',
                    Printable::of($relationship),
                ));
            }
        }

        $this->relationships = $relationships;
    }

    /**
     * @return non-empty-list<string>
     */
    public function relationships(): array
    {
        return $this->relationships;
    }

    public function depth(): int
    {
        return count($this->relationships);
    }

    public function toString(): string
    {
        return implode(self::SEPARATOR, $this->relationships);
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
