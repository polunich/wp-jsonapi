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

use InvalidArgumentException;
use OutOfBoundsException;
use Polunich\WpJsonApi\Error\BadRequestException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Sort\SortField;

/**
 * reads the `sort` parameter into a `Query\Sort\Sort`, in the order the client wrote the fields.
 *
 * a mistake is refused with 400 and never enters the `Sort`, which refuses an impossible value
 * with `InvalidArgumentException`, no HTTP error.
 * a field named twice is a duplicate whatever its direction: `-name,name` names one field twice.
 * the result holds the client's fields alone;
 * the default sort and `id` belong to `ResourceRegistry::readingSortOf()`
 *
 * @internal
 */
final readonly class SortParser
{
    private const SEPARATOR = ',';

    public function __construct(private ResourceRegistry $resources) {}

    /**
     * a refused field is left out, so the result is always a valid `Sort`;
     * the error list decides whether the request runs
     *
     * @param mixed    $sort     text; another shape is the guard's to refuse, read as absent
     * @param string   $type     the type of the records sorted
     * @param Strategy $strategy decides whether a nullable path is refused
     *
     * @throws OutOfBoundsException when the type, or a type a path runs through, is not declared
     */
    public function parse(mixed $sort, string $type, Strategy $strategy, ErrorList $errors): Sort
    {
        if (!is_string($sort)) {
            return new Sort([]);
        }

        if ($sort === '') {
            self::refuse($errors, ErrorCode::InvalidQueryParameter, ['name' => ParameterFamily::Sort->value]);

            return new Sort([]);
        }

        $fields = [];
        $seen = [];
        $reported = [];
        $malformed = false;

        foreach (explode(self::SEPARATOR, $sort) as $written) {
            try {
                $field = SortField::fromString($written);
            } catch (InvalidArgumentException) {
                $malformed = true;

                continue;
            }

            $path = $field->field->toString();

            if (array_key_exists($path, $seen)) {
                if (!array_key_exists($path, $reported)) {
                    self::refuse($errors, ErrorCode::DuplicateSortField, ['field' => $path]);
                    $reported[$path] = true;
                }

                continue;
            }

            $seen[$path] = true;

            if ($this->sorts($field->field, $type, $strategy, $errors)) {
                $fields[] = $field;
            }
        }

        if ($malformed) {
            // one error for the parameter: its detail names no field,
            // so one error per field would repeat the same text
            self::refuse($errors, ErrorCode::InvalidQueryParameter, ['name' => ParameterFamily::Sort->value]);
        }

        return new Sort($fields);
    }

    /**
     * a path the registry refuses gets the spec's plain 400 under any strategy.
     * a nullable path is refused under the cursor strategy only, with the profile's type link:
     * the route sorts by it but cannot page by it, since a cursor holds no null
     *
     * @throws OutOfBoundsException when the type, or a type the path runs through, is not declared
     */
    private function sorts(FieldPath $path, string $type, Strategy $strategy, ErrorList $errors): bool
    {
        try {
            $resolved = $this->resources->sortPath($type, $path);
        } catch (InvalidArgumentException) {
            self::refuse($errors, ErrorCode::UnsupportedSort, ['field' => $path->toString()]);

            return false;
        }

        if ($strategy === Strategy::Cursor && $resolved->nullable) {
            self::refuse(
                $errors,
                ErrorCode::UnsupportedSort,
                ['field' => $path->toString()],
                CursorProfile::UNSUPPORTED_SORT,
            );

            return false;
        }

        return true;
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private static function refuse(
        ErrorList $errors,
        ErrorCode $code,
        array $parameters,
        ?string $typeLink = null,
    ): void {
        $errors->add(new BadRequestException([
            new ErrorDescription($code, $parameters, parameter: ParameterFamily::Sort->value, typeLink: $typeLink),
        ]));
    }
}
