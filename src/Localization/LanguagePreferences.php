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

namespace Polunich\WpJsonApi\Localization;

/**
 * the language ranges of the source that decided the language of a request, by descending weight:
 * the elements of an `Accept-Language` field,
 * or the one range of a language query parameter at weight 1.
 * ranges of equal weight keep the order of the source,
 * which is the priority the matching rules walk (RFC 4647 3.3.1).
 *
 * what the source says about a tag follows from the whole list, never from one range,
 * so the class offers the whole list, `ranges()`, and the result over it, `select()`,
 * but no filtered part
 */
final readonly class LanguagePreferences
{
    /**
     * @var list<LanguageRange>
     */
    private array $ranges;

    /**
     * @param list<LanguageRange> $ranges in the order of the source
     */
    public function __construct(array $ranges)
    {
        $ordered = $ranges;
        usort(
            $ordered,
            static function (LanguageRange $first, LanguageRange $second): int {
                return $second->weight <=> $first->weight;
            },
        );

        $this->ranges = $ordered;
    }

    /**
     * every range, the rejections included
     *
     * @return list<LanguageRange>
     */
    public function ranges(): array
    {
        return $this->ranges;
    }

    /**
     * false when no source is present, or the field holds malformed elements only (RFC 9110 12.4.1)
     */
    public function hasPreference(): bool
    {
        return $this->ranges !== [];
    }

    /**
     * the tag of `$availableTags` the client prefers, null when it accepts none of them.
     *
     * basic filtering (RFC 4647 3.3.1) with the refinements of HTTP:
     * `*` covers only the tags no other range matches,
     * and of the ranges that cover a tag the longest decides
     * (RFC 2616 14.4, the scheme RFC 9110 12.5.4 calls identical to basic filtering),
     * so `en, en-GB;q=0` accepts `en-US` and refuses `en-GB`.
     * of two covering ranges of equal length, which only a repeated range can be,
     * the higher in the list decides.
     * a tag is not acceptable when its deciding range carries `q=0` (RFC 9110 12.4.2)
     * or when no range covers it (RFC 9110 12.4.3).
     *
     * of the acceptable tags the one whose deciding range stands highest wins,
     * and on a tie the one `$availableTags` names first; without a preference the first is taken.
     * null leaves the project the choice between 406 and a language of its own (RFC 9110 12.4.1)
     *
     * @param list<string> $availableTags the tags the project can serve, its own preference first
     */
    public function select(array $availableTags): ?string
    {
        $selected = null;
        $bestRank = null;

        foreach ($availableTags as $tag) {
            $rank = $this->rank($tag);

            if ($rank === null || ($bestRank !== null && $rank >= $bestRank)) {
                continue;
            }

            $selected = $tag;
            $bestRank = $rank;
        }

        return $selected;
    }

    /**
     * the position in `ranges()` of the range that decides the tag,
     * null when the tag is not acceptable; without any range every tag ranks 0.
     * the deciding range is chosen before its weight is read,
     * so a rejection counts only where it is the deciding range
     */
    private function rank(string $languageTag): ?int
    {
        $deciding = null;
        $decidingPosition = null;
        $decidingLength = 0;
        $wildcard = null;
        $wildcardPosition = null;

        foreach ($this->ranges as $position => $range) {
            if ($range->isWildcard()) {
                if ($wildcard === null) {
                    $wildcard = $range;
                    $wildcardPosition = $position;
                }

                continue;
            }

            $length = strlen($range->range);

            if ($length <= $decidingLength || !$range->matches($languageTag)) {
                continue;
            }

            $deciding = $range;
            $decidingPosition = $position;
            $decidingLength = $length;
        }

        if ($deciding === null) {
            $deciding = $wildcard;
            $decidingPosition = $wildcardPosition;
        }

        if ($deciding === null) {
            return $this->ranges === [] ? 0 : null;
        }

        return $deciding->isRejection() ? null : $decidingPosition;
    }
}
