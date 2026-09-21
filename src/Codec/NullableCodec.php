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

namespace Polunich\WpJsonApi\Codec;

use Override;
use Polunich\WpJsonApi\Codec\Schema\Schema;

/**
 * @template T
 *
 * @implements Codec<T|null>
 */
final readonly class NullableCodec implements Codec
{
    /**
     * @param Codec<T> $inner
     */
    public function __construct(private Codec $inner) {}

    /**
     * @return T|null
     */
    #[Override]
    public function decode(mixed $input, Pointer $pointer): mixed
    {
        return $input === null ? null : $this->inner->decode($input, $pointer);
    }

    #[Override]
    public function encode(mixed $value): mixed
    {
        return $value === null ? null : $this->inner->encode($value);
    }

    #[Override]
    public function schema(): Schema
    {
        return $this->inner->schema()->orNull();
    }
}
