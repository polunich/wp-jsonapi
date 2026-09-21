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
use Polunich\WpJsonApi\Text\Printable;

/**
 * where the language of a request comes from, in the order the API declares.
 *
 * the first source present in the request decides, and the ones after it are not read:
 * an explicit choice of the client replaces the preferences of its user agent,
 * since "It is not a good idea to use the HTTP Accept-Language header alone
 * to determine the locale of the user" (W3C, Accept-Language used for locale setting).
 * an empty list negotiates no language.
 *
 * the list is the one owner of what follows from it for a response:
 * the request fields `Vary` names, and the language parameter every link of a document carries
 *
 * @internal
 */
final readonly class LanguageSources
{
    /**
     * @param list<LanguageSourceMetadata> $sources
     *
     * @throws InvalidArgumentException when a source stands twice
     */
    public function __construct(public array $sources)
    {
        $seen = [];

        foreach ($sources as $source) {
            $name = $source->name();

            if (array_key_exists($name, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    'the language source "%s" is declared twice, and the first one present in a request decides,'
                    . ' so the second would never be read',
                    Printable::of($name),
                ));
            }

            $seen[$name] = true;
        }
    }

    /**
     * the source that decides the language of the request, null when none is present
     */
    public function deciding(ServerRequest $request): ?LanguageSourceMetadata
    {
        foreach ($this->sources as $source) {
            if ($source->isPresentIn($request)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * the names of the language query parameters, which every route takes
     *
     * @return list<string>
     */
    public function queryParameters(): array
    {
        $names = [];

        foreach ($this->sources as $source) {
            if ($source->queryParameter !== null) {
                $names[] = $source->queryParameter;
            }
        }

        return $names;
    }

    /**
     * the request fields that might have chosen the language:
     * `Accept-Language` where it is a source and no source before it is present,
     * its absence included, since `Vary` lists the fields
     * "that might have had a role in selecting the representation" (RFC 9110 12.5.5).
     * a query parameter is part of the target URI, which `Vary` never names
     *
     * @return list<string>
     */
    public function varyFields(ServerRequest $request): array
    {
        foreach ($this->sources as $source) {
            if ($source->queryParameter === null) {
                return [FieldName::ACCEPT_LANGUAGE];
            }

            if ($source->isPresentIn($request)) {
                return [];
            }
        }

        return [];
    }

    /**
     * the query that carries the language of the request on to a link:
     * the deciding query parameter with the value the client wrote;
     * empty where a field or nothing decided
     *
     * @return array<array-key, mixed>
     */
    public function linkQuery(ServerRequest $request): array
    {
        $name = $this->deciding($request)?->queryParameter;

        return $name === null ? [] : [$name => $request->query[$name]];
    }
}
