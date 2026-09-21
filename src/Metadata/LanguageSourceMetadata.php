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

namespace Polunich\WpJsonApi\Metadata;

use InvalidArgumentException;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Http\ServerRequest;

/**
 * one place a request may name its language in: a query parameter the API defines,
 * or the `Accept-Language` field (RFC 9110 12.5.4).
 * a source is present in a request that carries its parameter or its field, whatever the value
 *
 * @internal
 */
final readonly class LanguageSourceMetadata
{
    /**
     * @param ?string $queryParameter null for the `Accept-Language` field
     */
    private function __construct(public ?string $queryParameter) {}

    /**
     * @throws InvalidArgumentException when the name is no legal member name,
     *                                  names a family of the specification,
     *                                  or is one WordPress reads
     */
    public static function queryParameter(string $name): self
    {
        CustomQueryParameterName::check($name);

        return new self($name);
    }

    public static function acceptLanguage(): self
    {
        return new self(null);
    }

    public function isPresentIn(ServerRequest $request): bool
    {
        return $this->queryParameter === null
            ? $request->headers->has(FieldName::ACCEPT_LANGUAGE)
            : array_key_exists($this->queryParameter, $request->query);
    }

    /**
     * the query parameter or the field, as a message or a contract names it
     */
    public function name(): string
    {
        return $this->queryParameter ?? FieldName::ACCEPT_LANGUAGE;
    }
}
