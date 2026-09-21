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

/**
 * the If-None-Match precondition: `*` or a list of zero or more entity tags (RFC 9110 13.1.2).
 *
 * a value holds the wildcard or tags, never both: 13.1.2 calls a list with `*` in it invalid
 *
 * @internal
 */
final readonly class IfNoneMatch
{
    /** stands for any current representation (RFC 9110 13.1.2) */
    private const WILDCARD = '*';

    /**
     * @param list<EntityTag> $tags in the order sent, empty for the wildcard
     */
    private function __construct(private bool $wildcard, private array $tags) {}

    /**
     * reads a field value, or null when it is neither `*` nor a `#entity-tag` list;
     * null falls under rule 3 of RFC 9110 13.1.2: "Otherwise, the condition is true."
     *
     * a value of no element is the empty list, which matches no representation
     */
    public static function parse(string $fieldValue): ?self
    {
        if (trim($fieldValue, " \t") === self::WILDCARD) {
            return new self(true, []);
        }

        $tags = EntityTag::parseList($fieldValue);

        return $tags === null ? null : new self(false, $tags);
    }

    /**
     * whether the value matches the selected representation;
     * `$current` is its entity tag, null when it has none.
     * the If-None-Match condition is true exactly when this is false.
     *
     * ask only for a target with a current representation, since RFC 9110 13.2.1 ignores
     * the preconditions of a request that would fail without them.
     * `*` then always matches, with or without a tag (rule 1 of 13.1.2),
     * and a list matches only when a listed tag matches (rule 2)
     */
    public function matches(?EntityTag $current): bool
    {
        if ($this->wildcard) {
            return true;
        }

        if ($current === null) {
            return false;
        }

        foreach ($this->tags as $tag) {
            if ($tag->matchesWeakly($current)) {
                return true;
            }
        }

        return false;
    }
}
