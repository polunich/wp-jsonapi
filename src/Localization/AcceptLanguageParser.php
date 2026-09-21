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

use Polunich\WpJsonApi\Http\BasicLanguageRange;
use Polunich\WpJsonApi\Http\FieldValueGrammar;

/**
 * @internal
 */
final readonly class AcceptLanguageParser
{
    /**
     * one list element: a basic language range (RFC 4647 2.1)
     * with an optional weight (RFC 9110 12.4.2), whose parameter name is case-insensitive.
     *
     * `D` anchors `$` at the very end: without it `$` matches before a trailing LF,
     * which `trim()` leaves in place,
     * and a field value carrying CR, LF or NUL is "invalid and dangerous" (RFC 9110 5.5)
     */
    private const ELEMENT = '/^(?<range>' . BasicLanguageRange::GRAMMAR . '|\*)'
        . '(?:[ \t]*;[ \t]*(?i:'
        . FieldValueGrammar::WEIGHT_PARAMETER
        . ')=(?<weight>'
        . FieldValueGrammar::QVALUE
        . '))?$/D';

    /**
     * the elements of the field that follow `#( language-range [ weight ] )` (RFC 9110 12.5.4).
     *
     * a malformed element is left out instead of failing the request,
     * since a server may disregard a content negotiation field (RFC 9110 12.4.1).
     * so is an element carrying CR, LF or NUL:
     * rejecting the message or replacing the characters (RFC 9110 5.5) decides about the message,
     * which is not the parser's to decide; it only owes that no such character travels on.
     *
     * empty elements are ignored without a bound (RFC 9110 5.6.1.2):
     * the cost is linear in the length of the field,
     * so the header size limit of the server bounds it
     *
     * @param string|null $acceptLanguage null when the request has no such field,
     *                                    which states no preference (RFC 9110 12.4.1)
     */
    public function parse(?string $acceptLanguage): LanguagePreferences
    {
        $ranges = [];

        foreach (explode(',', $acceptLanguage ?? '') as $element) {
            $range = $this->parseElement(trim($element, " \t"));

            if ($range !== null) {
                $ranges[] = $range;
            }
        }

        return new LanguagePreferences($ranges);
    }

    private function parseElement(string $element): ?LanguageRange
    {
        if (preg_match(self::ELEMENT, $element, $matches) !== 1) {
            return null;
        }

        $weight = $matches['weight'] ?? '';

        return new LanguageRange(
            $matches['range'],
            $weight === '' ? FieldValueGrammar::DEFAULT_WEIGHT : (float) $weight,
        );
    }
}
