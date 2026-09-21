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
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Throwable;

/**
 * a path that is no relationship path is `invalid_query_parameter`;
 * a well-formed one `Metadata\ResourceRegistry::isIncludable()` refuses is `unsupported_include`
 *
 * @internal
 */
final readonly class IncludeParser
{
    private const PATH_SEPARATOR = ',';

    /**
     * the separator `IncludePath::toString()` writes, which parses no text itself
     */
    private const NAME_SEPARATOR = '.';

    public function __construct(private ResourceRegistry $resources) {}

    /**
     * null when there is no `include`, the empty set for an empty value:
     * a request that supplies `include` gets a compound document, even with nothing included
     *
     * @param mixed   $include      text; another shape is the guard's to refuse, read as absent
     * @param string  $type         where the paths start, the parent type on a relationship route
     * @param ?string $relationship the relationship of a relationship route
     *
     * @throws OutOfBoundsException when the type is not declared
     */
    public function parse(mixed $include, string $type, ?string $relationship, ErrorList $errors): ?IncludePaths
    {
        if (!is_string($include)) {
            return null;
        }

        if ($include === '') {
            return new IncludePaths([]);
        }

        $paths = [];

        foreach (explode(self::PATH_SEPARATOR, $include) as $written) {
            $path = $this->path($written, $type, $relationship, $errors);

            if ($path !== null) {
                $paths[] = $path;
            }
        }

        return new IncludePaths($paths);
    }

    /**
     * @throws OutOfBoundsException when the type is not declared
     */
    private function path(string $written, string $type, ?string $relationship, ErrorList $errors): ?IncludePath
    {
        try {
            $path = new IncludePath(explode(self::NAME_SEPARATOR, $written));
        } catch (InvalidArgumentException $refusal) {
            $errors->add(self::invalid($refusal));

            return null;
        }

        if (!$this->resources->isIncludable($type, $relationship, $path)) {
            $errors->add(self::unsupported($path));

            return null;
        }

        return $path;
    }

    private static function invalid(Throwable $previous): BadRequestException
    {
        return new BadRequestException(
            [new ErrorDescription(
                ErrorCode::InvalidQueryParameter,
                ['name' => ParameterFamily::Include->value],
                parameter: ParameterFamily::Include->value,
            )],
            $previous,
        );
    }

    private static function unsupported(IncludePath $path): BadRequestException
    {
        return new BadRequestException([
            new ErrorDescription(
                ErrorCode::UnsupportedInclude,
                ['path' => $path->toString()],
                parameter: ParameterFamily::Include->value,
            ),
        ]);
    }
}
