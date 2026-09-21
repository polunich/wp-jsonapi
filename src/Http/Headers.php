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

/**
 * a case-insensitive, multi-value map of header fields.
 *
 * a name is held in lower case with `_` read as `-`, so both spellings name one field.
 * a name that is no token (RFC 9110 5.1) is refused when stored,
 * since it could put a CR or LF into a response header line,
 * and a lookup by one returns the same as for an absent field.
 * a value is trimmed, and CR, LF and NUL in it become SP, as 5.5 allows a recipient
 *
 * public API: `ServerRequest` carries it, so its method and parameter names are public API too
 */
final readonly class Headers
{
    /**
     * keyed by array-key, not string:
     * a field name of digits is a token, and PHP turns such a key into an int
     *
     * @var array<array-key, list<string>> normalized name => its field line values, in order
     */
    private array $fields;

    /**
     * @param array<array-key, list<string>|string> $fields field name => one value or one per line
     *
     * @throws InvalidArgumentException when a field name is no token
     */
    public function __construct(array $fields = [])
    {
        $normalized = [];

        foreach ($fields as $name => $value) {
            $key = self::fieldName((string) $name);
            $values = is_string($value) ? [$value] : $value;

            foreach ($values as $line) {
                $normalized[$key][] = self::normalizeValue($line);
            }
        }

        $this->fields = $normalized;
    }

    public function has(string $name): bool
    {
        return isset($this->fields[self::normalizeName($name)]);
    }

    /**
     * the combined field value, its lines joined with `, ` (RFC 9110 5.2, 5.3),
     * or null when the field is absent
     */
    public function line(string $name): ?string
    {
        $values = $this->values($name);

        return $values === [] ? null : implode(', ', $values);
    }

    /**
     * the value of each field line, in the order received
     *
     * @return list<string>
     */
    public function values(string $name): array
    {
        return $this->fields[self::normalizeName($name)] ?? [];
    }

    /**
     * @return array<array-key, list<string>>
     */
    public function all(): array
    {
        return $this->fields;
    }

    /**
     * replaces every line of the field with one
     *
     * @throws InvalidArgumentException when the name is no token
     */
    public function with(string $name, string $value): self
    {
        $fields = $this->fields;
        $fields[self::fieldName($name)] = [self::normalizeValue($value)];

        return new self($fields);
    }

    /**
     * adds a line to the field, keeping the lines it has
     *
     * @throws InvalidArgumentException when the name is no token
     */
    public function withAdded(string $name, string $value): self
    {
        $fields = $this->fields;
        $fields[self::fieldName($name)][] = self::normalizeValue($value);

        return new self($fields);
    }

    private static function normalizeName(string $name): string
    {
        return strtr(strtolower($name), '_', '-');
    }

    /**
     * a refused name reaches the message cleaned like a value, so a CR or LF cannot break the line
     *
     * @throws InvalidArgumentException when the name is no token
     */
    private static function fieldName(string $name): string
    {
        $normalized = self::normalizeName($name);

        if (!FieldValueGrammar::isToken($normalized)) {
            throw new InvalidArgumentException(
                'a header field name must be a token, got "' . self::normalizeValue($name) . '"',
            );
        }

        return $normalized;
    }

    private static function normalizeValue(string $value): string
    {
        return trim(strtr($value, ["\r" => ' ', "\n" => ' ', "\0" => ' ']), " \t");
    }
}
