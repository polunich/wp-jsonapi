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

/**
 * the body an endpoint reads, resolved against the declaration:
 *
 * - `$type` is the type of the resource object or of the identifiers, the bound type for a record;
 * - `$parameter` names the bound record;
 * - `$codec` is the codec of a meta body
 *
 * @internal
 */
final readonly class BodyMetadata
{
    /**
     * @param ?Codec<mixed> $codec
     *
     * @throws InvalidArgumentException when a member the kind needs is missing,
     *                                  or one it does not take is given
     */
    public function __construct(
        public BodyShapeKind $kind,
        public ?string $type,
        public ?string $parameter,
        public ?Codec $codec,
    ) {
        $isMeta = $kind === BodyShapeKind::Meta;

        if (
            ($type === null) !== $isMeta
            || ($parameter !== null) !== ($kind === BodyShapeKind::Record)
            || ($codec === null) !== !$isMeta
        ) {
            throw new InvalidArgumentException(sprintf(
                '%s names %s, and it names exactly those',
                $kind->phrase(),
                match ($kind) {
                    BodyShapeKind::Meta => 'a codec of its meta',
                    BodyShapeKind::Record => 'a type and a bound parameter',
                    default => 'a type',
                },
            ));
        }
    }
}
