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
use Polunich\WpJsonApi\Http\FieldValueGrammar;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the applied profiles also hold the cursor profile of a reading that pages by cursor unasked,
 * so the requested ones are kept apart: they pick the strategy of a request that selects none.
 * the request's `Content-Type` is checked, not kept: a reader of the body takes it from the request
 *
 * @internal
 */
final readonly class Negotiation
{
    /**
     * @param MediaType    $contentType       with the applied extensions and profiles
     * @param list<string> $requestedProfiles recognized `Accept` profiles the response applies
     *
     * @throws InvalidArgumentException when the media type carries a weight,
     *                                  a JSON:API one a parameter other than `ext` and `profile`,
     *                                  or it lacks a requested profile
     */
    public function __construct(
        public MediaType $contentType,
        private array $requestedProfiles = [],
    ) {
        self::check($contentType);

        foreach ($requestedProfiles as $uri) {
            if (!in_array($uri, $contentType->profile(), true)) {
                throw new InvalidArgumentException(sprintf(
                    'the response applies every profile it was asked for, and "%s" is requested and not'
                    . ' applied [spec, Content Negotiation]',
                    Printable::of($uri),
                ));
            }
        }
    }

    /**
     * whether `Accept` asked for the profile and this response applies it
     */
    public function requestsProfile(string $uri): bool
    {
        return in_array($uri, $this->requestedProfiles, true);
    }

    /**
     * the strategy of a reading whose page parameters select none:
     * cursor where `Accept` asked for the cursor profile, since the profile defines that pagination
     */
    public function defaultPageStrategy(): Strategy
    {
        return $this->requestsProfile(CursorProfile::URI) ? Strategy::Cursor : Strategy::PageNumber;
    }

    /**
     * the media type of every document of this request, its errors included:
     * the negotiated one where it is JSON:API,
     * the JSON:API media type without extensions where another representation was picked
     */
    public function documentContentType(): MediaType
    {
        return $this->contentType->isJsonApi() ? $this->contentType : MediaType::jsonApi();
    }

    /**
     * the representation that was picked, as the route declares it:
     * the JSON:API media type without parameters, or the media type of a media response
     */
    public function representation(): MediaType
    {
        return $this->contentType->isJsonApi() ? MediaType::jsonApi() : $this->contentType;
    }

    /**
     * @throws InvalidArgumentException when the media type carries a weight,
     *                                  or a JSON:API one a parameter other than `ext` and `profile`
     */
    private static function check(MediaType $mediaType): void
    {
        foreach (array_keys($mediaType->isJsonApi() ? $mediaType->parameters : []) as $key) {
            $name = (string) $key;

            if ($name !== MediaType::EXT && $name !== MediaType::PROFILE) {
                throw new InvalidArgumentException(sprintf(
                    'the media type of the response carries the parameter "%s", and this specification'
                    . ' defines only ext and profile [spec, Content Negotiation]',
                    $name,
                ));
            }
        }

        if ($mediaType->weight !== FieldValueGrammar::DEFAULT_WEIGHT) {
            throw new InvalidArgumentException(
                'the media type of the response carries a weight, which belongs to a media range of a'
                . ' content negotiation field and to no Content-Type (RFC 9110 12.4.2)',
            );
        }
    }
}
