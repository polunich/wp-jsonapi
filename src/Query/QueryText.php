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

namespace Polunich\WpJsonApi\Query;

use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use stdClass;

/**
 * converts query text into the wire form a codec reads, the form a decoded JSON body takes.
 * the schema of the codec decides what each part becomes,
 * read with its `oneOf` and `anyOf` branches, depth first, as alternatives:
 *
 * - text becomes the first scalar type the schema admits and the text spells,
 *   tried as null, boolean, number, then string; literals are lowercase only, as in JSON;
 * - an array becomes a list where an alternative names `items`, each key kept,
 *   so an array the client did not write as a list still fails the list codec;
 *   where an alternative describes an object as well, only an array written as a list becomes one;
 * - an array becomes an object, a `stdClass`,
 *   where an alternative names `properties` or `additionalProperties`,
 *   each member read by the schema of its name.
 *   of several such alternatives, the first whose `const` members the text spells reads it,
 *   as the discriminator of a `OneOfCodec` picks a variant;
 *   where none does, the first reads it.
 *
 * what fits no rule reaches the codec unchanged, so the codec words the refusal
 *
 * @internal
 */
final readonly class QueryText
{
    /**
     * the shape of text alone
     */
    public const SCALAR = 'scalar';

    /**
     * the shape of an object whose members are text alone
     */
    public const OBJECT = 'object';

    /**
     * the shape of a list, or of an object with a member that is a list or an object
     */
    public const NESTED = 'nested';

    /**
     * the RFC 8259 number grammar
     */
    private const NUMBER = '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][-+]?[0-9]+)?$/D';

    /**
     * @param mixed  $value  text, or arrays of text where the client wrote brackets
     * @param Schema $schema the schema of the codec that reads the value
     */
    public static function toWire(mixed $value, Schema $schema): mixed
    {
        if (is_string($value)) {
            return self::text($value, $schema);
        }

        if (!is_array($value)) {
            return $value;
        }

        $alternatives = self::alternatives($schema);
        $items = self::items($alternatives);
        $objects = self::objects($alternatives);

        if ($items !== null && ($objects === [] || array_is_list($value))) {
            $converted = [];

            foreach ($value as $key => $item) {
                $converted[$key] = self::toWire($item, $items);
            }

            return $converted;
        }

        $object = self::variant($value, $objects);

        if ($object === null) {
            return $value;
        }

        $members = new stdClass();

        foreach ($value as $key => $member) {
            $name = (string) $key;
            $memberSchema = self::memberSchema($object, $name);
            $members->{$name} = $memberSchema === null ? $member : self::toWire($member, $memberSchema);
        }

        return $members;
    }

    /**
     * the shape a value of the schema takes in a query, by the alternatives `toWire()` reads:
     * a list anywhere, or a member that is no text, makes it nested
     *
     * @return self::SCALAR|self::OBJECT|self::NESTED
     */
    public static function shape(Schema $schema): string
    {
        $shape = self::SCALAR;

        foreach (self::alternatives($schema) as $alternative) {
            if ($alternative->items !== null) {
                return self::NESTED;
            }

            if (!self::describesObject($alternative)) {
                continue;
            }

            $members = $alternative->properties ?? [];

            if ($alternative->additionalProperties instanceof Schema) {
                $members[] = $alternative->additionalProperties;
            }

            foreach ($members as $member) {
                if (self::shape($member) !== self::SCALAR) {
                    return self::NESTED;
                }
            }

            $shape = self::OBJECT;
        }

        return $shape;
    }

    /**
     * a violation pointer, relative to the value, as the brackets the client wrote:
     * `/1` under `filter[tags][@in]` names `filter[tags][@in][1]`.
     * `strtr()` unescapes in one pass, so `~01` becomes `~1` and not `/`, as RFC 6901 requires
     */
    public static function brackets(Pointer $pointer): string
    {
        $path = $pointer->toString();

        if ($path === '') {
            return '';
        }

        $written = '';

        foreach (explode('/', substr($path, 1)) as $token) {
            $written .= '[' . strtr($token, ['~1' => '/', '~0' => '~']) . ']';
        }

        return $written;
    }

    /**
     * @return non-empty-list<Schema>
     */
    private static function alternatives(Schema $schema): array
    {
        $found = [$schema];

        foreach ([...$schema->oneOf ?? [], ...$schema->anyOf ?? []] as $branch) {
            $found = [...$found, ...self::alternatives($branch)];
        }

        return $found;
    }

    /**
     * @param list<Schema> $alternatives
     */
    private static function items(array $alternatives): ?Schema
    {
        foreach ($alternatives as $alternative) {
            if ($alternative->items !== null) {
                return $alternative->items;
            }
        }

        return null;
    }

    /**
     * @param list<Schema> $alternatives
     *
     * @return list<Schema>
     */
    private static function objects(array $alternatives): array
    {
        return array_values(array_filter($alternatives, self::describesObject(...)));
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<Schema>            $objects
     */
    private static function variant(array $value, array $objects): ?Schema
    {
        foreach ($objects as $object) {
            if (self::spellsConstants($value, $object)) {
                return $object;
            }
        }

        return $objects[0] ?? null;
    }

    /**
     * a member the value lacks excludes nothing: the codec refuses a missing discriminator itself
     *
     * @param array<array-key, mixed> $value
     */
    private static function spellsConstants(array $value, Schema $object): bool
    {
        foreach ($object->properties ?? [] as $name => $property) {
            if ($property->const === null || !array_key_exists($name, $value)) {
                continue;
            }

            $member = $value[$name];

            if (!is_string($member) || self::text($member, $property) !== $property->const) {
                return false;
            }
        }

        return true;
    }

    private static function memberSchema(Schema $object, string $name): ?Schema
    {
        $property = $object->properties[$name] ?? null;

        if ($property !== null) {
            return $property;
        }

        return $object->additionalProperties instanceof Schema ? $object->additionalProperties : null;
    }

    private static function describesObject(Schema $schema): bool
    {
        return $schema->properties !== null || $schema->additionalProperties instanceof Schema;
    }

    private static function text(string $text, Schema $schema): mixed
    {
        $types = $schema->scalarTypes();

        if (in_array(JsonType::Null, $types, true) && $text === 'null') {
            return null;
        }

        if (in_array(JsonType::Boolean, $types, true) && ($text === 'true' || $text === 'false')) {
            return $text === 'true';
        }

        if (
            (in_array(JsonType::Integer, $types, true) || in_array(JsonType::Number, $types, true))
            && preg_match(self::NUMBER, $text) === 1
        ) {
            // decoded as in a body, so the codec judges `18.0` or `1e2` as it would there;
            // a match of `NUMBER` is valid JSON, and an overflow to INF stays text,
            // since JSON has no infinite number
            $number = json_decode($text, false, flags: JSON_THROW_ON_ERROR);

            if (is_int($number) || (is_float($number) && is_finite($number))) {
                return $number;
            }
        }

        return $text;
    }
}
