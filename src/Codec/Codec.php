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

use InvalidArgumentException;
use Polunich\WpJsonApi\Codec\Schema\Schema;

/**
 * a codec reads the wire form of `json_decode($json, false)`: an object is a `stdClass`,
 * an array is a list. associative arrays would lose two distinctions JSON:API rests on.
 * `{}` and `[]` would both become `[]`,
 * so the malformed linkage `{"data":{}}` would read as an empty to-many relationship;
 * and `{"0":"x"}` would become the list `["x"]`, although `0` is a legal member name.
 * every codec of an object therefore refuses an array, and a list codec refuses a `stdClass`.
 *
 * the wire form is the only form a codec reads. text, such as a query string value,
 * is converted by the caller first, with the types `Schema::scalarTypes()` names;
 * a codec that accepted `"12"` as an integer would accept it inside a document body as well.
 *
 * PHP stores a member name of digits under an int key, so a decoded object is keyed `int|string`
 * and `(string)` gives the name back. the key type is `int|string`, not `array-key`:
 * PHPStan reads `array-key` as a benevolent union and lets it pass for a string unnoticed.
 *
 * the schema and the codec give one result: `decode()` enforces every keyword `schema()` writes,
 * and `schema()` writes every bound `decode()` enforces, platform limits included.
 * two exceptions: `format` is an annotation in JSON Schema 2020-12 (validation, section 7.2.1),
 * so a validator may accept a string the codec refuses,
 * and a `MappedCodec` may reject a value for a reason no keyword can express.
 * a member marked `readOnly` or `writeOnly` travels in one direction:
 * `decode()` refuses a read-only one and `encode()` leaves a write-only one out.
 *
 * `T` is covariant, so a `Codec<string>` is a `Codec<mixed>`,
 * as the members of an `ObjectCodec` need. `T` therefore appears only in the return of `decode()`,
 * and `encode()` takes `mixed` and checks the value itself
 *
 * @template-covariant T
 */
interface Codec
{
    /**
     * @return T
     *
     * @throws DecodeException with one violation per invalid member, each at `$pointer` or below it
     */
    public function decode(mixed $input, Pointer $pointer): mixed;

    /**
     * @throws InvalidArgumentException when the value is not one `decode()` returns
     */
    public function encode(mixed $value): mixed;

    public function schema(): Schema;
}
