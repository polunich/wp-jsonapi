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

namespace Polunich\WpJsonApi\Authentication;

use InvalidArgumentException;
use Polunich\WpJsonApi\Http\FieldValueGrammar;
use Polunich\WpJsonApi\Text\Printable;

/**
 * one challenge of a `WWW-Authenticate` field,
 * and the one place that knows how a challenge is written (RFC 9110):
 *
 * - `challenge = auth-scheme [ 1*SP ( token68 / #auth-param ) ]` (11.3):
 *   a scheme alone is a whole challenge, and only the auth-param form is supported,
 *   since token68 carries no names;
 * - no whitespace around `=`, as a sender MUST NOT generate BWS (5.6.3),
 *   and `, ` between parameters, the single SP where optional whitespace is preferred;
 * - a value is written as a token where it is one, and quoted otherwise;
 * - a pinned notation overrides that (11.2): RFC 9110 pins the realm, the scheme any other name.
 *   the caller passes the names its scheme pins in `alwaysQuoted` and `neverQuoted`,
 *   as Digest pins both ways (RFC 7616 3.3).
 *   a pinned name the challenge does not carry is allowed: the pin belongs to the scheme.
 *
 * names are matched case-insensitively (11.2), values are not:
 * a realm reaches the client spelled as given.
 * everything is checked when the challenge is built, so an existing one can always be written.
 *
 * the keys of `$parameters` are array-key: a name of decimal digits is a legal token,
 * and PHP turns such a key into an int, so a reader casts it back with `(string)`
 */
final readonly class Challenge
{
    /**
     * the one parameter RFC 9110 pins for every scheme:
     * a sender MUST only generate it as a quoted-string (11.5).
     * lower case, since names are compared after `strtolower()`
     */
    private const REALM = 'realm';

    /**
     * @param array<array-key, string> $parameters
     * @param list<string>             $alwaysQuoted the names the scheme pins to a quoted-string
     * @param list<string>             $neverQuoted  the names the scheme pins to a token
     *
     * @throws InvalidArgumentException when the scheme or a name is no token,
     *                                  a value fits in no header field
     *                                  or is no token where the scheme pins it to one,
     *                                  a name is pinned to both notations or the realm to a token,
     *                                  or a name is given twice
     */
    public function __construct(
        public string $scheme,
        public array $parameters = [],
        public array $alwaysQuoted = [],
        public array $neverQuoted = [],
    ) {
        if (!FieldValueGrammar::isToken($scheme)) {
            throw new InvalidArgumentException(
                'an authentication scheme must be a token, got "' . Printable::of($scheme) . '"',
            );
        }

        foreach ([...$alwaysQuoted, ...$neverQuoted] as $pinned) {
            self::assertName($pinned);
        }

        $quoted = self::byLowerCase($alwaysQuoted);
        $tokens = self::byLowerCase($neverQuoted);

        foreach ($tokens as $lowered => $spelling) {
            if ($lowered === self::REALM) {
                throw new InvalidArgumentException(
                    'the authentication parameter "' . $spelling
                    . '" is the realm, which a sender may only write as a quoted-string',
                );
            }

            if (isset($quoted[$lowered])) {
                throw new InvalidArgumentException(
                    'the authentication parameter "' . $spelling . '" is pinned to a token and, as "'
                    . $quoted[$lowered] . '", to a quoted-string',
                );
            }
        }

        $seen = [];

        foreach ($parameters as $key => $value) {
            $name = (string) $key;

            self::assertName($name);

            if (!FieldValueGrammar::isText($value)) {
                throw new InvalidArgumentException(
                    'the authentication parameter "' . $name . '" holds a value that no header field can carry',
                );
            }

            $lowered = strtolower($name);

            if (isset($seen[$lowered])) {
                throw new InvalidArgumentException(
                    'the authentication parameter "' . $name . '" is given twice, first as "' . $seen[$lowered] . '"',
                );
            }

            $seen[$lowered] = $name;

            if (isset($tokens[$lowered]) && !FieldValueGrammar::isToken($value)) {
                throw new InvalidArgumentException(
                    'the authentication parameter "' . $name
                    . '" holds a value that is no token, and its scheme pins it to one',
                );
            }
        }
    }

    /**
     * the value of one field line: several challenges on one line "might not be interoperable"
     * (RFC 9110 11.6.1), so each is written on a line of its own.
     *
     * a `neverQuoted` value needs no branch: the constructor made sure it is a token,
     * which `FieldValueGrammar::render()` writes bare
     */
    public function toHeaderValue(): string
    {
        $quoted = self::byLowerCase([self::REALM, ...$this->alwaysQuoted]);
        $written = [];

        foreach ($this->parameters as $key => $value) {
            $name = (string) $key;
            $written[] = $name . '=' . (isset($quoted[strtolower($name)])
                ? FieldValueGrammar::quote($value)
                : FieldValueGrammar::render($value));
        }

        return $written === [] ? $this->scheme : $this->scheme . ' ' . implode(', ', $written);
    }

    /**
     * @throws InvalidArgumentException when the name is no token
     */
    private static function assertName(string $name): void
    {
        if (!FieldValueGrammar::isToken($name)) {
            throw new InvalidArgumentException(
                'an authentication parameter name must be a token, got "' . Printable::of($name) . '"',
            );
        }
    }

    /**
     * @param list<string> $names
     *
     * @return array<array-key, string>
     */
    private static function byLowerCase(array $names): array
    {
        $byLowerCase = [];

        foreach ($names as $name) {
            $byLowerCase[strtolower($name)] ??= $name;
        }

        return $byLowerCase;
    }
}
