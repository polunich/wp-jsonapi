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

namespace Polunich\WpJsonApi\Parsing;

use OutOfBoundsException;
use Polunich\WpJsonApi\Error\BadRequestException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\ParameterFamily;

/**
 * an empty value is the empty list of fields, not an absent fieldset:
 * the spec lets a server send all fields only for a type the client did not name.
 *
 * an undeclared type, or a key that is no member name, is a parameter the server cannot process:
 * `unsupported_query_parameter`.
 * an undeclared field, or a segment that is no member name, is `unknown_field`.
 * a fieldset for a type the response does not carry changes nothing and is no mistake
 *
 * @internal
 */
final readonly class FieldsetParser
{
    private const SEPARATOR = ',';

    public function __construct(private ResourceRegistry $resources) {}

    /**
     * a refused fieldset or field is left out; the error list decides whether the request runs
     *
     * @param mixed $fields a map of type names to text, as the client bracketed them
     */
    public function parse(mixed $fields, ErrorList $errors): SparseFieldsets
    {
        if (!is_array($fields)) {
            return new SparseFieldsets([]);
        }

        $fieldsets = [];

        foreach ($fields as $key => $value) {
            $type = (string) $key;

            if (!$this->resources->has($type)) {
                $errors->add(self::unsupported($type));

                continue;
            }

            if (!is_string($value)) {
                $errors->add(self::invalid($type));

                continue;
            }

            $fieldsets[$type] = $this->fields($type, $value, $errors);
        }

        return new SparseFieldsets($fieldsets);
    }

    /**
     * @return list<string>
     *
     * @throws OutOfBoundsException when the type is not declared
     */
    private function fields(string $type, string $value, ErrorList $errors): array
    {
        if ($value === '') {
            return [];
        }

        $resource = $this->resources->get($type);
        $fields = [];

        foreach (explode(self::SEPARATOR, $value) as $field) {
            if (
                !array_key_exists($field, $resource->attributes)
                && !array_key_exists($field, $resource->relationships)
            ) {
                $errors->add(self::unknown($type, $field));

                continue;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    private static function parameter(string $type): string
    {
        return ParameterFamily::Fields->parameter($type);
    }

    private static function unsupported(string $type): BadRequestException
    {
        $parameter = self::parameter($type);

        return new BadRequestException([
            new ErrorDescription(ErrorCode::UnsupportedQueryParameter, ['name' => $parameter], parameter: $parameter),
        ]);
    }

    private static function invalid(string $type): BadRequestException
    {
        $parameter = self::parameter($type);

        return new BadRequestException([
            new ErrorDescription(ErrorCode::InvalidQueryParameter, ['name' => $parameter], parameter: $parameter),
        ]);
    }

    private static function unknown(string $type, string $field): BadRequestException
    {
        return new BadRequestException([
            new ErrorDescription(
                ErrorCode::UnknownField,
                ['type' => $type, 'field' => $field],
                parameter: self::parameter($type),
            ),
        ]);
    }
}
