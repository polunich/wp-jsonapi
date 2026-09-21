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

namespace Polunich\WpJsonApi\Codec;

use Override;
use Stringable;

/**
 * a JSON Pointer of RFC 6901
 */
final readonly class Pointer implements Stringable
{
    /**
     * @param list<string> $tokens the reference tokens, unescaped
     */
    private function __construct(private array $tokens) {}

    /**
     * the empty pointer `""`, which names the whole value
     */
    public static function root(): self
    {
        return new self([]);
    }

    public function descend(string $token): self
    {
        $tokens = $this->tokens;
        $tokens[] = $token;

        return new self($tokens);
    }

    /**
     * `~` is escaped before `/` (RFC 6901, section 3):
     * the other order would turn the `~1` written for a `/` into `~01`
     */
    public function toString(): string
    {
        $pointer = '';

        foreach ($this->tokens as $token) {
            $pointer .= '/' . str_replace(['~', '/'], ['~0', '~1'], $token);
        }

        return $pointer;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
