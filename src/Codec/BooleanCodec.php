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

use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;

/**
 * @implements Codec<bool>
 */
final readonly class BooleanCodec implements Codec
{
    #[Override]
    public function decode(mixed $input, Pointer $pointer): bool
    {
        if (!is_bool($input)) {
            throw new DecodeException([
                ViolationCode::InvalidType->at($pointer, JsonType::Boolean->value),
            ]);
        }

        return $input;
    }

    #[Override]
    public function encode(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('the value must be a boolean');
        }

        return $value;
    }

    #[Override]
    public function schema(): Schema
    {
        return new Schema(type: JsonType::Boolean);
    }
}
