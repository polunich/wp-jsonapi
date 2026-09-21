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

namespace Polunich\WpJsonApi\Tests\Fixtures\Metadata;

use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\SegmentCodec;
use Polunich\WpJsonApi\Codec\Violation;

/**
 * a string codec that reports whatever schema and segment pattern it is given,
 * to test what is derived from a schema rather than from a codec class.
 * it decodes and encodes strings alone, whatever schema it reports
 *
 * @internal
 */
final readonly class DescribedCodec implements SegmentCodec
{
    /**
     * @param non-empty-string $segmentPattern
     */
    public function __construct(private Schema $schema, private string $segmentPattern = '[^/]+') {}

    #[Override]
    public function decode(mixed $input, Pointer $pointer): string
    {
        if (!is_string($input)) {
            throw new DecodeException([new Violation($pointer, 'invalid_type', ['expected' => 'string'])]);
        }

        return $input;
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
        return $this->schema;
    }

    #[Override]
    public function segmentPattern(): string
    {
        return $this->segmentPattern;
    }
}
