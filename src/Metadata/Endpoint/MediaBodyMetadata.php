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
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Text\Printable;

/**
 * a body an endpoint reads in a media type other than JSON:API:
 *
 * - a JSON text of `application/json` or a `+json` type, read by its codec;
 * - a form, `application/x-www-form-urlencoded` or `multipart/form-data`,
 *   of declared fields and, for multipart, declared files;
 * - the bytes of any other media type, as they came
 *
 * @internal
 */
final readonly class MediaBodyMetadata
{
    /**
     * @param ?Codec<mixed>           $codec
     * @param list<FormFieldMetadata> $fields
     * @param array<string, bool>     $files  name => whether required, in a multipart form
     *
     * @throws InvalidArgumentException when the media type is a range, carries a weight,
     *                                  is the JSON:API one or does not fit the kind,
     *                                  a part is given that the kind does not take,
     *                                  or a form declares a name twice
     */
    public function __construct(
        public MediaType $mediaType,
        public MediaBodyShapeKind $kind,
        public ?Codec $codec = null,
        public array $fields = [],
        public array $files = [],
    ) {
        MediaResponseMetadata::checkMediaType($mediaType, 'a body');

        $essence = $mediaType->essence();
        $form = $essence === MediaType::FORM_URLENCODED || $essence === MediaType::MULTIPART_FORM_DATA;

        $refusal = match ($kind) {
            MediaBodyShapeKind::Json => !$mediaType->isJson()
                ? 'a JSON body is read in a JSON media type, application/json or one of the +json suffix'
                : ($codec === null ? 'a JSON body is read by a codec' : null),
            MediaBodyShapeKind::Form => match (true) {
                !$form => sprintf(
                    'a form is read in %s or %s',
                    MediaType::FORM_URLENCODED,
                    MediaType::MULTIPART_FORM_DATA,
                ),
                $files !== [] && $essence !== MediaType::MULTIPART_FORM_DATA => sprintf(
                    'a form carries files in %s alone',
                    MediaType::MULTIPART_FORM_DATA,
                ),
                default => null,
            },
            MediaBodyShapeKind::Bytes => null,
        };

        if ($refusal === null && $kind !== MediaBodyShapeKind::Json && $codec !== null) {
            $refusal = 'a codec reads a JSON body alone';
        }

        if ($refusal === null && $kind !== MediaBodyShapeKind::Form && ($fields !== [] || $files !== [])) {
            $refusal = 'fields and files belong to a form';
        }

        if ($refusal !== null) {
            throw new InvalidArgumentException(sprintf(
                'a body of "%s" is refused: %s',
                $mediaType->toHeaderValue(),
                $refusal,
            ));
        }

        $names = [];

        foreach ([...array_map(static function (FormFieldMetadata $field): string {
            return $field->name;
        }, $fields), ...array_map('strval', array_keys($files))] as $name) {
            if (in_array($name, $names, true)) {
                throw new InvalidArgumentException(sprintf(
                    'a form declares the name "%s" twice, and each field or file is declared once',
                    Printable::of($name),
                ));
            }

            $names[] = $name;
        }
    }
}
