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

namespace Polunich\WpJsonApi\Tests\Fixtures\Codec;

use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\SegmentCodec;
use Polunich\WpJsonApi\Codec\Violation;

/**
 * an id codec that trims its segment, so a text of spaces decodes to the empty id,
 * which identifies no resource
 *
 * @internal
 */
final readonly class TrimmingCodec implements SegmentCodec
{
    #[Override]
    public function decode(mixed $input, Pointer $pointer): string
    {
        if (!is_string($input)) {
            throw new DecodeException([
                new Violation($pointer, 'invalid_type', ['expected' => JsonType::String->value]),
            ]);
        }

        return trim($input);
    }

    #[Override]
    public function encode(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('the value must be a string');
        }

        return $value;
    }

    #[Override]
    public function schema(): Schema
    {
        return new Schema(type: JsonType::String);
    }

    #[Override]
    public function segmentPattern(): string
    {
        return '[^/]+';
    }
}
