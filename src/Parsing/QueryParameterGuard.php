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

use Polunich\WpJsonApi\Error\BadRequestException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Metadata\RouteQuery;
use Polunich\WpJsonApi\Metadata\WordPressReservedParameters;
use Polunich\WpJsonApi\Query\ParameterFamily;

/**
 * refuses every query parameter the route does not process:
 * 400 `unsupported_query_parameter`, with the parameter in `source.parameter`.
 *
 * a route processes what `Metadata\RouteQuery` returns for it,
 * and every route the language parameters of the API:
 * a declared parameter passes in any shape, since its codec reads it,
 * and so does a language parameter, which `LanguageReader` reads where it decides.
 * a name that breaks the naming conventions gets the same response as one the route cannot process:
 * the spec asks 400 for both, and the remedy, dropping the parameter, is the same.
 * a declared name passes whatever its spelling, since an API may admit one of a-z alone
 *
 * the guard checks the shape of a processed family only; what it holds is its parser's to refuse,
 * so one mistake yields one error object
 *
 * @internal
 */
final readonly class QueryParameterGuard
{
    /**
     * @param list<string> $languageParameters the language query parameters every route takes
     */
    public function __construct(private array $languageParameters) {}

    /**
     * @param array<array-key, mixed> $query
     */
    public function check(array $query, RouteQuery $route, ErrorList $errors): void
    {
        $declared = $route->declaredNames();

        foreach ($query as $key => $value) {
            $name = (string) $key;

            if (
                WordPressReservedParameters::isConsumed($name)
                || in_array($name, $declared, true)
                || in_array($name, $this->languageParameters, true)
            ) {
                continue;
            }

            $family = ParameterFamily::tryFrom($name);

            // keeps a refused reserved parameter of WordPress refused,
            // should a family ever take its name
            if (
                $family !== null
                && !WordPressReservedParameters::isRefused($name)
                && in_array($family, $route->families, true)
                && self::hasForm($family, $value)
            ) {
                continue;
            }

            self::refuse($name, $value, $errors);
        }
    }

    private static function hasForm(ParameterFamily $family, mixed $value): bool
    {
        return match ($family) {
            // text under the base name, `sort=-name`: `sort[0]` is a parameter no route processes
            ParameterFamily::Include, ParameterFamily::Sort => !is_array($value),
            // a map under the base name, `page[size]`: a bare `page` carries nothing to read
            ParameterFamily::Fields, ParameterFamily::Page => is_array($value),
            // any shape: the parser refuses a malformed filter as `invalid_query_parameter`,
            // and a refusal here would add a second error object saying to drop the parameter
            ParameterFamily::Filter => true,
        };
    }

    /**
     * one error per leaf, named as the client wrote it: `filter[@or][0][name][@eq]`.
     * the query array PHP decoded holds no reference and `max_input_nesting_level` caps its depth,
     * so the recursion ends.
     * empty brackets carry the key PHP gave them: `filter[]` is `filter[0]`
     */
    private static function refuse(string $name, mixed $value, ErrorList $errors): void
    {
        if (is_array($value) && $value !== []) {
            foreach ($value as $key => $nested) {
                self::refuse($name . '[' . $key . ']', $nested, $errors);
            }

            return;
        }

        $errors->add(new BadRequestException([
            new ErrorDescription(
                ErrorCode::UnsupportedQueryParameter,
                ['name' => $name],
                parameter: $name,
            ),
        ]));
    }
}
