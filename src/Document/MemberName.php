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

namespace Polunich\WpJsonApi\Document;

use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Text\Printable;
use Stringable;

/**
 * a member name by the JSON:API rules (section "Member Names"),
 * the only definition of that alphabet in the library.
 *
 * a name holds at least one character. `a-z`, `A-Z`, `0-9` and U+0080 and above stand anywhere;
 * `-`, `_` and space anywhere but first or last; `@` only first, which makes an @-member.
 * every other character below U+0080 is reserved.
 *
 * only the syntax is checked;
 * whether a context accepts an @-member or a given name is decided where the name is used.
 * an extension member such as `atomic:operations` is refused as a whole, since `:` is reserved;
 * the part after the colon is the member name.
 *
 * the name is read as UTF-8 (RFC 8259, section 8.1); a string that is not valid UTF-8 is refused
 */
final readonly class MemberName implements Stringable
{
    private const GLOBALLY_ALLOWED = 'a-zA-Z0-9\x{0080}-\x{10FFFF}';

    private const INNER_ONLY = '\-_ ';

    /**
     * a name ends in a globally allowed character; before it stands nothing,
     * or `@` or a globally allowed character, followed by any allowed characters.
     * the alternation keeps `@` first and `-`, `_`, space off both ends
     */
    private const PATTERN = '/^(?:@[' . self::GLOBALLY_ALLOWED . self::INNER_ONLY . ']*|['
        . self::GLOBALLY_ALLOWED . '][' . self::GLOBALLY_ALLOWED . self::INNER_ONLY . ']*)?['
        . self::GLOBALLY_ALLOWED . ']$/uD';

    private function __construct(private string $name) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function from(string $name): self
    {
        if (!self::isValid($name)) {
            throw new InvalidArgumentException(
                'a member name must hold at least one character, all of them letters, digits or '
                . 'characters above U+007F, with "-", "_" and " " allowed anywhere but at the ends '
                . 'and "@" as the first character: ' . Printable::of($name),
            );
        }

        return new self($name);
    }

    public static function isValid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }

    public function toString(): string
    {
        return $this->name;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
