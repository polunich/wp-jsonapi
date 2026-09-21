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

namespace Polunich\WpJsonApi\Http;

use InvalidArgumentException;
use Polunich\WpJsonApi\Text\Printable;

/**
 * an entity tag: the opaque validator of one representation (RFC 9110 8.8.3).
 *
 * the library writes strong tags only,
 * but a client may send weak ones in If-Match and If-None-Match, so both are representable.
 * the one owner of the entity-tag grammar, the `#entity-tag` list of those two fields included
 *
 * @internal
 */
final readonly class EntityTag
{
    /** case-sensitive: `w/"x"` is no weak tag (RFC 9110 8.8.3) */
    private const WEAK_PREFIX = 'W/';

    /**
     * @param string $value the opaque tag, without its quotes
     *
     * @throws InvalidArgumentException when the value holds a character outside etagc
     */
    public function __construct(public string $value, public bool $weak = false)
    {
        $length = strlen($this->value);

        for ($index = 0; $index < $length; ++$index) {
            if (!self::isOpaqueCharacter($this->value[$index])) {
                throw new InvalidArgumentException(
                    'an entity tag must hold no quote, space or control character, got "'
                    . Printable::of($this->value) . '"',
                );
            }
        }
    }

    /**
     * reads a `#entity-tag` list (RFC 9110 5.6.1.2),
     * or null when any element is no entity-tag, the wildcard `*` among them.
     *
     * empty elements are skipped, so a value of none (empty, whitespace, commas) is the empty list.
     * not split on commas: a comma is an etagc, so one between quotes belongs to the tag
     *
     * @return list<self>|null in the order sent, duplicates kept
     */
    public static function parseList(string $fieldValue): ?array
    {
        $length = strlen($fieldValue);
        $position = 0;
        $tags = [];

        while (true) {
            $position += strspn($fieldValue, " \t,", $position);

            if ($position >= $length) {
                return $tags;
            }

            $tag = self::readTag($fieldValue, $position);

            if ($tag === null) {
                return null;
            }

            $tags[] = $tag;
            $position += strspn($fieldValue, " \t", $position);

            if ($position < $length && $fieldValue[$position] !== ',') {
                return null;
            }
        }
    }

    /**
     * the strong comparison of RFC 9110 8.8.3.2, the one If-Match requires
     */
    public function matchesStrongly(self $other): bool
    {
        return !$this->weak && !$other->weak && $this->value === $other->value;
    }

    /**
     * the weak comparison of RFC 9110 8.8.3.2, the one If-None-Match requires
     */
    public function matchesWeakly(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toHeaderValue(): string
    {
        return ($this->weak ? self::WEAK_PREFIX : '') . '"' . $this->value . '"';
    }

    /**
     * reads one entity-tag at `$position` and moves `$position` past it,
     * or null when none starts there.
     *
     * the first DQUOTE after the opening one closes the tag: etagc excludes DQUOTE,
     * and an entity tag has no quoted-pair, so a backslash is an ordinary etagc
     */
    private static function readTag(string $value, int &$position): ?self
    {
        $length = strlen($value);
        $weak = substr($value, $position, strlen(self::WEAK_PREFIX)) === self::WEAK_PREFIX;

        if ($weak) {
            $position += strlen(self::WEAK_PREFIX);
        }

        if ($position >= $length || $value[$position] !== '"') {
            return null;
        }

        $closing = strpos($value, '"', $position + 1);

        if ($closing === false) {
            return null;
        }

        $opaque = substr($value, $position + 1, $closing - $position - 1);
        $position = $closing + 1;

        try {
            return new self($opaque, $weak);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * etagc of RFC 9110 8.8.3
     */
    private static function isOpaqueCharacter(string $character): bool
    {
        $code = ord($character);

        return $code === 0x21 || ($code >= 0x23 && $code <= 0x7E) || $code >= 0x80;
    }
}
