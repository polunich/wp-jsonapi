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

namespace Polunich\WpJsonApi\Metadata\Endpoint;

use InvalidArgumentException;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Http\FieldValueGrammar;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Text\Printable;

/**
 * a representation other than JSON:API that an endpoint responds with in a 200:
 * the bytes a handler hands over, or a JSON value its codec writes where the codec is given.
 *
 * the media type is a type, never a range,
 * and never the JSON:API one, whose documents the response shapes describe.
 * a JSON value takes a JSON media type, `application/json` or a `+json` one
 *
 * @internal
 */
final readonly class MediaResponseMetadata
{
    /**
     * @param ?Codec<mixed> $codec the codec of a JSON value, null for bytes
     *
     * @throws InvalidArgumentException when the media type is a range, carries a weight
     *                                  or is the JSON:API one,
     *                                  or a codec comes with a media type that is no JSON
     */
    public function __construct(
        public MediaType $mediaType,
        public ?Codec $codec,
    ) {
        self::checkMediaType($mediaType, 'a response of 200');

        if ($codec !== null && !$mediaType->isJson()) {
            throw new InvalidArgumentException(sprintf(
                'a JSON response is written in a JSON media type, application/json or one of the +json suffix, and'
                . ' "%s" is none',
                $mediaType->toHeaderValue(),
            ));
        }
    }

    /**
     * @throws InvalidArgumentException when the text is no media type
     */
    public static function mediaType(string $text, string $part): MediaType
    {
        return MediaType::parse($text) ?? throw new InvalidArgumentException(sprintf(
            '%s names "%s", which is no media type: a type, a subtype and parameters (RFC 9110, section 8.3.1)',
            $part,
            Printable::of($text),
        ));
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function checkMediaType(MediaType $mediaType, string $part): void
    {
        if ($mediaType->isRange() || $mediaType->weight !== FieldValueGrammar::DEFAULT_WEIGHT) {
            throw new InvalidArgumentException(sprintf(
                '%s names the media type "%s", and a representation has one media type, no range and no weight',
                $part,
                $mediaType->toHeaderValue(),
            ));
        }

        if ($mediaType->isJsonApi()) {
            throw new InvalidArgumentException(sprintf(
                '%s names the JSON:API media type, whose documents the shapes of the library describe',
                $part,
            ));
        }
    }
}
