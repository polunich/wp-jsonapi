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

namespace Polunich\WpJsonApi\Endpoint;

use OutOfBoundsException;
use Polunich\WpJsonApi\Http\UploadedFile;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Text\Printable;

/**
 * a field is decoded by its codec, and one the request leaves out is absent, never null;
 * a file is one PHP stored, and an optional file the request leaves out is absent
 */
final readonly class FormBody
{
    /**
     * @param Values<mixed>               $fields
     * @param array<string, UploadedFile> $files  by the name of the field
     */
    public function __construct(
        public Values $fields,
        public array $files,
    ) {}

    public function hasFile(string $name): bool
    {
        return array_key_exists($name, $this->files);
    }

    /**
     * @throws OutOfBoundsException when the request carries no such file
     */
    public function file(string $name): UploadedFile
    {
        return $this->files[$name] ?? throw new OutOfBoundsException(sprintf(
            'the form carries no file "%s"',
            Printable::of($name),
        ));
    }
}
