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
 * the codec reads its text as a query value is read, arrays where the name used brackets
 *
 * @internal
 */
final readonly class FormFieldMetadata
{
    /**
     * @param Codec<mixed> $codec
     *
     * @throws InvalidArgumentException when the name is empty
     */
    public function __construct(
        public string $name,
        public Codec $codec,
        public bool $required,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('a form field has a name, and this one is empty');
        }
    }
}
