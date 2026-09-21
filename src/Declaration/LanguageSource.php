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

namespace Polunich\WpJsonApi\Declaration;

use InvalidArgumentException;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;

/**
 * one place a request may name its language in:
 * a query parameter, such as `uiLocale`, or the `Accept-Language` field.
 *
 * a query parameter carries exactly one basic language range, such as `uk` or `en-GB`
 * (RFC 4647 2.1); a request with an empty or malformed value,
 * or with the parameter in brackets, gets 400.
 * every route takes it, and every link of a response carries it on as the client wrote it
 */
final readonly class LanguageSource
{
    private function __construct(private LanguageSourceMetadata $source) {}

    /**
     * a name of a-z alone, such as `locale`, fails the API build
     * unless the API turns off its strict rule of query parameter names
     *
     * @param string $name a legal member name, such as `uiLocale`,
     *                     and none WordPress reads, such as `_locale`
     *
     * @throws InvalidArgumentException when the name is no legal member name,
     *                                  names a family of the specification,
     *                                  or is one WordPress reads
     */
    public static function queryParameter(string $name): self
    {
        return new self(LanguageSourceMetadata::queryParameter($name));
    }

    /**
     * the language ranges of the `Accept-Language` field (RFC 9110 12.5.4)
     */
    public static function acceptLanguage(): self
    {
        return new self(LanguageSourceMetadata::acceptLanguage());
    }

    /**
     * @internal
     */
    public function build(): LanguageSourceMetadata
    {
        return $this->source;
    }
}
