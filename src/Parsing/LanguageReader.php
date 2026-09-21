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
use Polunich\WpJsonApi\Http\BasicLanguageRange;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\AcceptLanguageParser;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Metadata\LanguageSources;

/**
 * the language preferences of a request, read from the source that decides:
 * `Metadata\LanguageSources::deciding()`.
 *
 * a query parameter carries exactly one basic language range without `*` (RFC 4647 2.1),
 * which becomes the one preference, of weight 1.
 * the client chose it on purpose, so a value the grammar refuses is 400,
 * unlike a malformed element of `Accept-Language`, which is dropped.
 * with no source present the request states no preference
 *
 * @internal
 */
final readonly class LanguageReader
{
    public function __construct(
        private LanguageSources $languageSources,
        private AcceptLanguageParser $acceptLanguage,
    ) {}

    /**
     * @throws BadRequestException when the deciding query parameter is empty, in brackets,
     *                             or no basic language range
     */
    public function read(ServerRequest $request): LanguagePreferences
    {
        $source = $this->languageSources->deciding($request);

        if ($source === null) {
            return new LanguagePreferences([]);
        }

        $name = $source->queryParameter;

        if ($name === null) {
            return $this->acceptLanguage->parse($request->headers->line($source->name()));
        }

        $value = $request->query[$name];

        if (!is_string($value) || !BasicLanguageRange::isValid($value)) {
            throw new BadRequestException([new ErrorDescription(
                ErrorCode::InvalidQueryParameter,
                ['name' => $name],
                parameter: $name,
            )]);
        }

        return new LanguagePreferences([new LanguageRange($value)]);
    }
}
