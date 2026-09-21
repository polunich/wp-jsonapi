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
use Override;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Text\Printable;
use stdClass;

/**
 * decodes into an array of the declared members in declaration order,
 * followed by the undeclared members `$additionalProperties` keeps, in document order.
 * a missing required member is reported at the object itself,
 * since a pointer names only a value that exists;
 * a read-only member the input carries is refused at its own pointer.
 * a required read-only member is required of `encode()` alone,
 * and a required write-only member of `decode()` alone
 *
 * @implements Codec<array<int|string, mixed>>
 */
final readonly class ObjectCodec implements Codec
{
    /**
     * @param array<int|string, Codec<mixed>> $properties           member name => codec
     * @param list<string>                    $required
     * @param bool                            $additionalProperties keep undeclared members raw
     * @param list<string>                    $readOnly             members of responses alone
     * @param list<string>                    $writeOnly            members of requests alone
     *
     * @throws InvalidArgumentException when a required, read-only or write-only name
     *                                  is no declared property,
     *                                  or a member is both read-only and write-only
     */
    public function __construct(
        private array $properties,
        private array $required = [],
        private bool $additionalProperties = false,
        private array $readOnly = [],
        private array $writeOnly = [],
    ) {
        foreach (['required' => $required, 'read-only' => $readOnly, 'write-only' => $writeOnly] as $kind => $names) {
            foreach ($names as $name) {
                if (!array_key_exists($name, $properties)) {
                    throw new InvalidArgumentException('a ' . $kind . ' member must be a declared'
                        . ' property: ' . Printable::of($name));
                }
            }
        }

        foreach ($readOnly as $name) {
            if (in_array($name, $writeOnly, true)) {
                throw new InvalidArgumentException('a member travels in one direction, and '
                    . Printable::of($name) . ' is both read-only and write-only');
            }
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    #[Override]
    public function decode(mixed $input, Pointer $pointer): array
    {
        if (!$input instanceof stdClass) {
            throw new DecodeException([
                ViolationCode::InvalidType->at($pointer, JsonType::Object->value),
            ]);
        }

        $members = get_object_vars($input);
        $violations = [];
        $decoded = [];

        foreach ($this->properties as $key => $codec) {
            $name = (string) $key;

            if (!array_key_exists($name, $members)) {
                if (in_array($name, $this->required, true) && !in_array($name, $this->readOnly, true)) {
                    $violations[] = ViolationCode::MissingMember->at($pointer, $name);
                }

                continue;
            }

            if (in_array($name, $this->readOnly, true)) {
                $violations[] = ViolationCode::ReadOnlyMember->at($pointer->descend($name), $name);

                continue;
            }

            try {
                $decoded[$name] = $codec->decode($members[$name], $pointer->descend($name));
            } catch (DecodeException $exception) {
                foreach ($exception->violations() as $violation) {
                    $violations[] = $violation;
                }
            }
        }

        foreach ($members as $key => $member) {
            $name = (string) $key;

            if (array_key_exists($name, $this->properties)) {
                continue;
            }

            if ($this->additionalProperties) {
                $decoded[$name] = $member;

                continue;
            }

            $violations[] = ViolationCode::UnexpectedMember->at($pointer->descend($name), $name);
        }

        if ($violations !== []) {
            throw new DecodeException($violations);
        }

        return $decoded;
    }

    #[Override]
    public function encode(mixed $value): stdClass
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('the value must be an array of members');
        }

        $encoded = [];

        foreach ($this->properties as $key => $codec) {
            $name = (string) $key;

            if (in_array($name, $this->writeOnly, true)) {
                continue;
            }

            if (!array_key_exists($name, $value)) {
                if (in_array($name, $this->required, true)) {
                    throw new InvalidArgumentException('a required member is missing: ' . Printable::of($name));
                }

                continue;
            }

            $encoded[$name] = $codec->encode($value[$name]);
        }

        foreach ($value as $key => $member) {
            $name = (string) $key;

            if (array_key_exists($name, $this->properties)) {
                continue;
            }

            if (!$this->additionalProperties) {
                throw new InvalidArgumentException('the member is not declared: ' . Printable::of($name));
            }

            $encoded[$name] = $member;
        }

        return (object) $encoded;
    }

    #[Override]
    public function schema(): Schema
    {
        $properties = [];

        foreach ($this->properties as $key => $codec) {
            $name = (string) $key;
            $properties[$name] = $codec->schema()->withAnnotations(
                readOnly: in_array($name, $this->readOnly, true) ? true : null,
                writeOnly: in_array($name, $this->writeOnly, true) ? true : null,
            );
        }

        return new Schema(
            type: JsonType::Object,
            properties: $properties,
            required: $this->required,
            additionalProperties: $this->additionalProperties,
        );
    }
}
