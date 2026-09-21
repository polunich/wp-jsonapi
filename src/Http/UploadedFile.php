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

namespace Polunich\WpJsonApi\Http;

use InvalidArgumentException;

/**
 * one file of a `multipart/form-data` request as PHP stored it, a file PHP refused included.
 *
 * the name and the media type are the client's claims, unchecked.
 * PHP removes the temporary file when the request ends,
 * unless it is moved away with `move_uploaded_file()`.
 *
 * public API: `ServerRequest` carries it, so its constructor parameter names are public API too
 */
final readonly class UploadedFile
{
    /**
     * @param string $clientMediaType the empty string for none
     * @param int    $size            in octets
     * @param string $temporaryPath   the empty string where PHP stored no file
     * @param int    $error           an `UPLOAD_ERR_*` code
     *
     * @throws InvalidArgumentException when the size is negative or the error no code PHP defines
     */
    public function __construct(
        public string $clientName,
        public string $clientMediaType,
        public int $size,
        public string $temporaryPath,
        public int $error,
    ) {
        if ($size < 0) {
            throw new InvalidArgumentException(sprintf('the size of an uploaded file counts octets, got %d', $size));
        }

        if (!in_array($error, [
            UPLOAD_ERR_OK,
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE,
            UPLOAD_ERR_PARTIAL,
            UPLOAD_ERR_NO_FILE,
            UPLOAD_ERR_NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE,
            UPLOAD_ERR_EXTENSION,
        ], true)) {
            throw new InvalidArgumentException(sprintf(
                'the error of an uploaded file is an UPLOAD_ERR_* code of PHP, got %d',
                $error,
            ));
        }
    }
}
