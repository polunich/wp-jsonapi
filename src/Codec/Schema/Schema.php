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

namespace Polunich\WpJsonApi\Codec\Schema;

use Closure;
use InvalidArgumentException;
use stdClass;

/**
 * the JSON Schema 2020-12 keywords a codec describes its values with.
 *
 * null means the keyword is absent, so neither `const` nor `default` can be null;
 * a value that may only be null is written as the type null.
 * `toObject()` leaves out null keywords and empty lists and writes the rest in a fixed order,
 * so the same schema always encodes to the same bytes.
 *
 * `toObject()` returns a `stdClass` for the schema and for every schema nested in a keyword.
 * as a PHP array, the empty schema, which allows every value, would encode as `[]`, no schema,
 * and `properties` keyed by digits as a JSON array.
 *
 * `readOnly` and `writeOnly` are annotations (JSON Schema 2020-12, validation, section 9.4)
 * whose enforcement is left to the owner of the data,
 * and OpenAPI 3.1.2 dropped the 3.0 rule that limits `required` on them to one direction,
 * so a schema is split by direction with `forRequest()` and `forResponse()`
 */
final readonly class Schema
{
    /**
     * also the fixed order of `scalarTypes()`
     */
    private const SCALAR_TYPES = [
        JsonType::Null,
        JsonType::Boolean,
        JsonType::Integer,
        JsonType::Number,
        JsonType::String,
    ];

    /**
     * @param JsonType|list<JsonType>|null $type
     * @param list<mixed>|null             $enum
     * @param array<int|string, self>|null $properties           member name => schema
     * @param list<string>|null            $required
     * @param self|bool|null               $additionalProperties the schema of undeclared members,
     *                                                           or whether they are allowed
     * @param list<self>|null              $oneOf
     * @param list<self>|null              $allOf
     * @param list<self>|null              $anyOf
     * @param list<mixed>|null             $examples
     */
    public function __construct(
        public JsonType|array|null $type = null,
        public ?array $enum = null,
        public mixed $const = null,
        public ?Format $format = null,
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        public ?self $items = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?array $properties = null,
        public ?array $required = null,
        public self|bool|null $additionalProperties = null,
        public ?array $oneOf = null,
        public ?array $allOf = null,
        public ?array $anyOf = null,
        public ?self $if = null,
        public ?self $then = null,
        public ?self $else = null,
        public ?string $description = null,
        public ?array $examples = null,
        public mixed $default = null,
        public ?bool $deprecated = null,
        public ?bool $readOnly = null,
        public ?bool $writeOnly = null,
    ) {
        if (is_array($type) && $type === []) {
            throw new InvalidArgumentException('a type list must name at least one type');
        }
    }

    /**
     * null joins both the type list and the enum, since each keyword is checked on its own.
     * a schema without a type or with a `const` cannot list null in its type
     * and is wrapped in `anyOf` with the null type instead
     */
    public function orNull(): self
    {
        if ($this->type === null || $this->const !== null) {
            return new self(anyOf: [$this, new self(type: JsonType::Null)]);
        }

        $types = is_array($this->type) ? $this->type : [$this->type];

        if (in_array(JsonType::Null, $types, true)) {
            return $this;
        }

        $types[] = JsonType::Null;
        $enum = $this->enum;

        if ($enum !== null && !in_array(null, $enum, true)) {
            $enum[] = null;
        }

        return $this->copyWith(
            $types,
            $enum,
            $this->description,
            $this->examples,
            $this->default,
            $this->deprecated,
            $this->readOnly,
            $this->writeOnly,
        );
    }

    /**
     * a null argument keeps the current value of its keyword
     *
     * @param list<mixed>|null $examples
     */
    public function withAnnotations(
        ?string $description = null,
        ?array $examples = null,
        mixed $default = null,
        ?bool $deprecated = null,
        ?bool $readOnly = null,
        ?bool $writeOnly = null,
    ): self {
        return $this->copyWith(
            $this->type,
            $this->enum,
            $description ?? $this->description,
            $examples ?? $this->examples,
            $default ?? $this->default,
            $deprecated ?? $this->deprecated,
            $readOnly ?? $this->readOnly,
            $writeOnly ?? $this->writeOnly,
        );
    }

    /**
     * what a request may carry: every member marked `readOnly` is left out,
     * of `properties` and of `required`, at every depth
     */
    public function forRequest(): self
    {
        return $this->without(static function (self $member): bool {
            return $member->readOnly === true;
        });
    }

    /**
     * what a response carries: every member marked `writeOnly` is left out,
     * of `properties` and of `required`, at every depth
     */
    public function forResponse(): self
    {
        return $this->without(static function (self $member): bool {
            return $member->writeOnly === true;
        });
    }

    public function toObject(): stdClass
    {
        return (object) $this->keywords();
    }

    /**
     * empty when the schema admits only objects or arrays.
     * without a `type`, the types come from `const`, `enum`, `oneOf` or `anyOf`.
     * a schema with none of them admits every scalar type: an absent keyword constrains nothing,
     * and `allOf`, `if`, `then` and `else` are not read, as no built-in codec writes them
     *
     * @return list<JsonType>
     */
    public function scalarTypes(): array
    {
        $admitted = $this->admittedScalarTypes();
        $types = [];

        foreach (self::SCALAR_TYPES as $type) {
            if (in_array($type, $admitted, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @return array<string, mixed>
     */
    private function keywords(): array
    {
        $keywords = [];

        if ($this->type !== null) {
            $keywords['type'] = is_array($this->type)
                ? array_map(static function (JsonType $type): string {
                    return $type->value;
                }, $this->type)
                : $this->type->value;
        }

        if ($this->enum !== null && $this->enum !== []) {
            $keywords['enum'] = $this->enum;
        }

        if ($this->const !== null) {
            $keywords['const'] = $this->const;
        }

        if ($this->format !== null) {
            $keywords['format'] = $this->format->value;
        }

        if ($this->minimum !== null) {
            $keywords['minimum'] = $this->minimum;
        }

        if ($this->maximum !== null) {
            $keywords['maximum'] = $this->maximum;
        }

        if ($this->minLength !== null) {
            $keywords['minLength'] = $this->minLength;
        }

        if ($this->maxLength !== null) {
            $keywords['maxLength'] = $this->maxLength;
        }

        if ($this->pattern !== null) {
            $keywords['pattern'] = $this->pattern;
        }

        if ($this->items !== null) {
            $keywords['items'] = $this->items->toObject();
        }

        if ($this->minItems !== null) {
            $keywords['minItems'] = $this->minItems;
        }

        if ($this->maxItems !== null) {
            $keywords['maxItems'] = $this->maxItems;
        }

        if ($this->properties !== null && $this->properties !== []) {
            $properties = [];

            foreach ($this->properties as $name => $schema) {
                $properties[(string) $name] = $schema->toObject();
            }

            $keywords['properties'] = (object) $properties;
        }

        if ($this->required !== null && $this->required !== []) {
            $keywords['required'] = $this->required;
        }

        if ($this->additionalProperties !== null) {
            $keywords['additionalProperties'] = $this->additionalProperties instanceof self
                ? $this->additionalProperties->toObject()
                : $this->additionalProperties;
        }

        if ($this->oneOf !== null && $this->oneOf !== []) {
            $keywords['oneOf'] = self::toObjects($this->oneOf);
        }

        if ($this->allOf !== null && $this->allOf !== []) {
            $keywords['allOf'] = self::toObjects($this->allOf);
        }

        if ($this->anyOf !== null && $this->anyOf !== []) {
            $keywords['anyOf'] = self::toObjects($this->anyOf);
        }

        if ($this->if !== null) {
            $keywords['if'] = $this->if->toObject();
        }

        if ($this->then !== null) {
            $keywords['then'] = $this->then->toObject();
        }

        if ($this->else !== null) {
            $keywords['else'] = $this->else->toObject();
        }

        if ($this->description !== null) {
            $keywords['description'] = $this->description;
        }

        if ($this->examples !== null && $this->examples !== []) {
            $keywords['examples'] = $this->examples;
        }

        if ($this->default !== null) {
            $keywords['default'] = $this->default;
        }

        if ($this->deprecated !== null) {
            $keywords['deprecated'] = $this->deprecated;
        }

        if ($this->readOnly !== null) {
            $keywords['readOnly'] = $this->readOnly;
        }

        if ($this->writeOnly !== null) {
            $keywords['writeOnly'] = $this->writeOnly;
        }

        return $keywords;
    }

    /**
     * @param list<self> $schemas
     *
     * @return list<stdClass>
     */
    private static function toObjects(array $schemas): array
    {
        return array_map(static function (self $schema): stdClass {
            return $schema->toObject();
        }, $schemas);
    }

    /**
     * unordered, with repetitions. `oneOf` and `anyOf` branches are merged into one union,
     * exact because no built-in codec writes both on one schema
     *
     * @return list<JsonType>
     */
    private function admittedScalarTypes(): array
    {
        if ($this->type !== null) {
            $declared = is_array($this->type) ? $this->type : [$this->type];

            return array_values(array_filter(
                $declared,
                static function (JsonType $type): bool {
                    return in_array($type, self::SCALAR_TYPES, true);
                },
            ));
        }

        if ($this->const !== null) {
            $type = self::typeOfValue($this->const);

            return $type === null ? [] : [$type];
        }

        if ($this->enum !== null && $this->enum !== []) {
            return self::typesOfValues($this->enum);
        }

        $branches = array_merge($this->oneOf ?? [], $this->anyOf ?? []);

        if ($branches === []) {
            return self::SCALAR_TYPES;
        }

        $types = [];

        foreach ($branches as $branch) {
            foreach ($branch->scalarTypes() as $type) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<JsonType>
     */
    private static function typesOfValues(array $values): array
    {
        $types = [];

        foreach ($values as $value) {
            $type = self::typeOfValue($value);

            if ($type !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * null for an object or an array
     */
    private static function typeOfValue(mixed $value): ?JsonType
    {
        return match (true) {
            $value === null => JsonType::Null,
            is_bool($value) => JsonType::Boolean,
            is_int($value) => JsonType::Integer,
            is_float($value) => JsonType::Number,
            is_string($value) => JsonType::String,
            default => null,
        };
    }

    /**
     * @param Closure(self): bool $excluded whether a member is left out
     */
    private function without(Closure $excluded): self
    {
        $properties = null;
        $required = $this->required;

        if ($this->properties !== null) {
            $properties = [];

            foreach ($this->properties as $name => $member) {
                if ($excluded($member)) {
                    $required = $required === null ? null : array_values(array_diff($required, [(string) $name]));

                    continue;
                }

                $properties[$name] = $member->without($excluded);
            }
        }

        return new self(
            type: $this->type,
            enum: $this->enum,
            const: $this->const,
            format: $this->format,
            minimum: $this->minimum,
            maximum: $this->maximum,
            minLength: $this->minLength,
            maxLength: $this->maxLength,
            pattern: $this->pattern,
            items: $this->items?->without($excluded),
            minItems: $this->minItems,
            maxItems: $this->maxItems,
            properties: $properties,
            required: $required,
            additionalProperties: $this->additionalProperties instanceof self
                ? $this->additionalProperties->without($excluded)
                : $this->additionalProperties,
            oneOf: self::eachWithout($this->oneOf, $excluded),
            allOf: self::eachWithout($this->allOf, $excluded),
            anyOf: self::eachWithout($this->anyOf, $excluded),
            if: $this->if?->without($excluded),
            then: $this->then?->without($excluded),
            else: $this->else?->without($excluded),
            description: $this->description,
            examples: $this->examples,
            default: $this->default,
            deprecated: $this->deprecated,
            readOnly: $this->readOnly,
            writeOnly: $this->writeOnly,
        );
    }

    /**
     * @param list<self>|null     $schemas
     * @param Closure(self): bool $excluded
     *
     * @return list<self>|null
     */
    private static function eachWithout(?array $schemas, Closure $excluded): ?array
    {
        if ($schemas === null) {
            return null;
        }

        return array_map(static function (self $schema) use ($excluded): self {
            return $schema->without($excluded);
        }, $schemas);
    }

    /**
     * @param JsonType|list<JsonType>|null $type
     * @param list<mixed>|null             $enum
     * @param list<mixed>|null             $examples
     */
    private function copyWith(
        JsonType|array|null $type,
        ?array $enum,
        ?string $description,
        ?array $examples,
        mixed $default,
        ?bool $deprecated,
        ?bool $readOnly,
        ?bool $writeOnly,
    ): self {
        return new self(
            type: $type,
            enum: $enum,
            const: $this->const,
            format: $this->format,
            minimum: $this->minimum,
            maximum: $this->maximum,
            minLength: $this->minLength,
            maxLength: $this->maxLength,
            pattern: $this->pattern,
            items: $this->items,
            minItems: $this->minItems,
            maxItems: $this->maxItems,
            properties: $this->properties,
            required: $this->required,
            additionalProperties: $this->additionalProperties,
            oneOf: $this->oneOf,
            allOf: $this->allOf,
            anyOf: $this->anyOf,
            if: $this->if,
            then: $this->then,
            else: $this->else,
            description: $description,
            examples: $examples,
            default: $default,
            deprecated: $deprecated,
            readOnly: $readOnly,
            writeOnly: $writeOnly,
        );
    }
}
