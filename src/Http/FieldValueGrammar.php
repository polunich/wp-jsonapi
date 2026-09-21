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
 * the header field value grammar of RFC 9110: token (5.6.2), quoted-string (5.6.4),
 * the characters a field value may carry (5.5) and the weight (12.4.2).
 *
 * the one owner of that grammar in the library.
 * it knows no field: which field carries a value, and whether it is quoted, is up to the caller
 *
 * @internal
 */
final readonly class FieldValueGrammar
{
    /** the weight of an element without a `q` parameter (RFC 9110 12.4.2) */
    public const DEFAULT_WEIGHT = 1.0;

    /** the weight parameter, case-insensitive on the wire (RFC 9110 12.4.2), held in lower case */
    public const WEIGHT_PARAMETER = 'q';

    /**
     * qvalue of RFC 9110 12.4.2, without delimiters and anchors so another pattern can embed it
     */
    public const QVALUE = '0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?';

    private const WHOLE_QVALUE = '/^(?:' . self::QVALUE . ')$/D';

    /** tchar of RFC 9110 5.6.2, in the order the rule lists it */
    private const TOKEN_CHARS = '!#$%&\'*+-.^_`|~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function isToken(string $value): bool
    {
        return $value !== '' && self::tokenLength($value) === strlen($value);
    }

    /**
     * the length of the token at `$offset`, 0 when none starts there,
     * for a scanner that reads a field value in place
     */
    public static function tokenLength(string $value, int $offset = 0): int
    {
        return strspn($value, self::TOKEN_CHARS, $offset);
    }

    /**
     * the weight a qvalue stands for, or null when the value is no qvalue (RFC 9110 12.4.2)
     */
    public static function weight(string $qvalue): ?float
    {
        return preg_match(self::WHOLE_QVALUE, $qvalue) === 1 ? (float) $qvalue : null;
    }

    /**
     * whether a qvalue can write the number: 0 to 1 with at most three decimals (RFC 9110 12.4.2).
     * NAN and the infinities fail the range check
     */
    public static function isWeight(float $weight): bool
    {
        return $weight >= 0.0 && $weight <= 1.0 && round($weight, 3) === $weight;
    }

    /**
     * whether every character may stand in a field value (RFC 9110 5.5).
     * CR, LF and NUL fail, so a value cannot start a header line of its own.
     *
     * the check is per character, not positional:
     * the empty value passes, and so does a leading or trailing SP,
     * since a value checked here sits after `=` or between quotes
     */
    public static function isText(string $value): bool
    {
        $length = strlen($value);

        for ($index = 0; $index < $length; ++$index) {
            $code = ord($value[$index]);

            if ($code !== 9 && ($code < 32 || $code === 127)) {
                return false;
            }
        }

        return true;
    }

    /**
     * the value as a quoted-string (RFC 9110 5.6.4), even when it is a token.
     *
     * only DQUOTE and the backslash become quoted-pairs, all a sender should escape;
     * a control character has no quoted-pair form and is refused
     *
     * @throws InvalidArgumentException when the value holds a character no field value may carry
     */
    public static function quote(string $value): string
    {
        if (!self::isText($value)) {
            throw new InvalidArgumentException(
                'a header field value may carry HTAB, SP, a visible character or obs-text, got "'
                . Printable::of($value) . '"',
            );
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * a token bare, anything else as a quoted-string:
     * both spellings of a token are equivalent (RFC 9110 5.6.6), so the shorter one is written
     *
     * @throws InvalidArgumentException when the value holds a character no field value may carry
     */
    public static function render(string $value): string
    {
        return self::isToken($value) ? $value : self::quote($value);
    }
}
