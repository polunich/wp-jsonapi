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
 * the If-Match precondition: `*` or a list of zero or more entity tags (RFC 9110 13.1.1).
 *
 * a value holds the wildcard or tags, never both: 13.1.1 calls a list with `*` in it invalid
 *
 * @internal
 */
final readonly class IfMatch
{
    /** stands for any current representation (RFC 9110 13.1.1) */
    private const WILDCARD = '*';

    /**
     * @param list<EntityTag> $tags in the order sent, empty for the wildcard
     */
    private function __construct(private bool $wildcard, private array $tags) {}

    /**
     * reads a field value, or null when it is neither `*` nor a `#entity-tag` list;
     * null falls under rule 3 of RFC 9110 13.1.1: "Otherwise, the condition is false."
     *
     * a value of no element is the empty list, which holds for no representation
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
     * whether the precondition holds for the selected representation;
     * `$current` is its entity tag, null when it has none.
     *
     * ask only for a target with a current representation, since RFC 9110 13.2.1 ignores
     * the preconditions of a request that would fail without them.
     * `*` then always holds, with or without a tag (rule 1 of 13.1.1),
     * and a list holds only when a listed tag matches (rule 2)
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
            if ($tag->matchesStrongly($current)) {
                return true;
            }
        }

        return false;
    }

    /**
     * the record versions the listed tags stand for under one negotiation,
     * so the precondition can be checked against a stored version without the tag rule;
     * null for `*`, which admits any version.
     *
     * a tag `VersionTag::decode()` refuses stands for no version, so the list may be empty.
     * for any stored version, being in the list agrees with `matches()` on its tag.
     * the order sent and duplicates are kept
     *
     * @return list<string>|null
     */
    public function versions(?string $contentLanguage, MediaType $contentType): ?array
    {
        if ($this->wildcard) {
            return null;
        }

        $versions = [];

        foreach ($this->tags as $tag) {
            $version = VersionTag::decode($tag, $contentLanguage, $contentType);

            if ($version !== null) {
                $versions[] = $version;
            }
        }

        return $versions;
    }
}
