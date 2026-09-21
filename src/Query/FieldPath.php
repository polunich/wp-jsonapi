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

namespace Polunich\WpJsonApi\Query;

use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Document\MemberName;
use Polunich\WpJsonApi\Text\Printable;
use Stringable;

/**
 * the path to a field of the resource being read: the relationships it runs through,
 * then the field, `owner.name`.
 * the text form joins the segments with `.`, as JSON:API recommends for sort fields;
 * `.` is reserved in member names, so it never stands inside a segment.
 *
 * whether the resource has these relationships and this field is not checked here
 */
final readonly class FieldPath implements Stringable
{
    private const SEPARATOR = '.';

    /**
     * no attribute or relationship may be named `id`, so the one-segment path `id`
     * always means the resource's own id
     */
    private const ID = 'id';

    /**
     * @var non-empty-list<string>
     */
    private array $segments;

    /**
     * @param list<string> $segments the relationship names, then the field
     *
     * @throws InvalidArgumentException when the path names nothing, or a segment is no member name
     */
    public function __construct(array $segments)
    {
        if ($segments === []) {
            throw new InvalidArgumentException('a field path names at least one field');
        }

        foreach ($segments as $segment) {
            if (!MemberName::isValid($segment)) {
                throw new InvalidArgumentException(sprintf(
                    'segment "%s" of a field path is no member name, so no field and no relationship carries it',
                    Printable::of($segment),
                ));
            }
        }

        $this->segments = $segments;
    }

    /**
     * reads exactly the texts `isValid()` accepts, and `toString()` writes each back unchanged
     *
     * @param string $path `name` or `owner.name`
     *
     * @throws InvalidArgumentException when `isValid()` refuses the text
     */
    public static function fromString(string $path): self
    {
        if (!self::isValid($path)) {
            throw new InvalidArgumentException(sprintf(
                'field path "%s" is no list of member names separated by "."',
                Printable::of($path),
            ));
        }

        return new self(explode(self::SEPARATOR, $path));
    }

    public static function id(): self
    {
        return new self([self::ID]);
    }

    /**
     * a leading, trailing or doubled `.` leaves an empty segment, which `MemberName` refuses
     */
    public static function isValid(string $path): bool
    {
        foreach (explode(self::SEPARATOR, $path) as $segment) {
            if (!MemberName::isValid($segment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `owner.id` is not the id: it names the id of a related resource
     */
    public function isId(): bool
    {
        return $this->segments === [self::ID];
    }

    /**
     * whether the path names an id: the resource's own, `id`, or a related resource's, `owner.id`
     */
    public function endsAtId(): bool
    {
        return $this->name() === self::ID;
    }

    /**
     * @return non-empty-list<string>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    public function name(): string
    {
        $segments = $this->segments;

        return array_pop($segments);
    }

    /**
     * @return list<string>
     */
    public function relationships(): array
    {
        return array_slice($this->segments, 0, -1);
    }

    public function depth(): int
    {
        return count($this->segments) - 1;
    }

    public function toString(): string
    {
        return implode(self::SEPARATOR, $this->segments);
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
