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

use InvalidArgumentException;
use Polunich\WpJsonApi\Http\BasicLanguageRange;
use Polunich\WpJsonApi\Http\FieldValueGrammar;
use Polunich\WpJsonApi\Text\Printable;

/**
 * one language preference of a request: a basic language range (RFC 4647 2.1)
 * and its weight (RFC 9110 12.4.2), from an element of `Accept-Language`
 * or from a language query parameter
 */
final readonly class LanguageRange
{
    /**
     * `language-range = (1*8ALPHA *("-" 1*8alphanum)) / "*"` (RFC 4647 2.1).
     *
     * `D` anchors `$` at the very end: without it `$` matches before a trailing LF,
     * and since `$range` is public the LF could travel on into a response field (RFC 9110 5.5)
     */
    private const GRAMMAR = '/^(?:' . BasicLanguageRange::GRAMMAR . '|\*)$/D';

    /**
     * @param string $range  as the client wrote it; its case carries no meaning (RFC 4647 2)
     * @param float  $weight 1 without a `q` parameter, 0 for not acceptable (RFC 9110 12.4.2).
     *                       the default is a literal, not `FieldValueGrammar::DEFAULT_WEIGHT`:
     *                       a public signature names no internal class
     *
     * @throws InvalidArgumentException when the range does not follow RFC 4647 2.1,
     *                                  or the weight is outside 0 to 1
     *                                  or has more than three decimals
     */
    public function __construct(
        public string $range,
        public float $weight = 1.0,
    ) {
        if (preg_match(self::GRAMMAR, $range) !== 1) {
            throw new InvalidArgumentException(
                sprintf('language range "%s" does not follow the grammar of RFC 4647 2.1', Printable::of($range)),
            );
        }

        if (!FieldValueGrammar::isWeight($weight)) {
            // NAN reaches this line, and from PHP 8.5 `%s` warns when it coerces NAN:
            // `var_export()` writes it silently
            throw new InvalidArgumentException(
                sprintf(
                    'weight %s is not a number between 0 and 1 with at most three decimals (RFC 9110 12.4.2)',
                    var_export($weight, true),
                ),
            );
        }
    }

    public function isWildcard(): bool
    {
        return $this->range === '*';
    }

    public function isRejection(): bool
    {
        return $this->weight === 0.0;
    }

    /**
     * basic filtering (RFC 4647 3.3.1): compared case-insensitively, the range equals the tag
     * or a prefix of it followed by `-`; `*` matches every tag.
     *
     * a match alone does not decide the tag:
     * the first matching range can accept a language another range rejects,
     * so `LanguagePreferences::select()` decides over the whole list
     */
    public function matches(string $languageTag): bool
    {
        if ($this->isWildcard()) {
            return true;
        }

        $range = strtolower($this->range);
        $tag = strtolower($languageTag);

        return $tag === $range || str_starts_with($tag, $range . '-');
    }
}
