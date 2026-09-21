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

/**
 * an incoming HTTP request, reduced to the values the library reads.
 *
 * the library's own value, not a PSR-7 abstraction.
 * public API: project code receives it, so its constructor parameter names are public API too.
 *
 * `$query` holds text as the client wrote it, arrays of text where it used brackets;
 * typing it is left to the schema of each parameter.
 * `$body` stays raw, so a body that is no JSON becomes an error document, not a type error.
 *
 * a query key of decimal digits arrives as an int key at any depth, as `parse_str()` writes it,
 * so `$query` is keyed by array-key and a reader casts a name back with `(string)`.
 * `$path` is keyed by string: the library names the route parameters,
 * and a PCRE group name of digits does not compile.
 *
 * `$form` holds the fields of a form body as `parse_str()` writes them, like `$query`.
 * `$files` holds the files of a `multipart/form-data` body by field name,
 * an `UploadedFile` per file and an array where the name used brackets.
 * `$formDiscarded` says PHP dropped the fields and files of a form over `post_max_size`.
 * a multipart body arrives with no bytes, so whether a request carries content is `hasContent()`
 */
final readonly class ServerRequest
{
    /**
     * @param string                  $method in upper case
     * @param array<array-key, mixed> $query
     * @param array<string, string>   $path   percent-decoded once
     * @param string                  $body   the empty string for no content
     * @param array<array-key, mixed> $form
     * @param array<array-key, mixed> $files
     */
    public function __construct(
        public string $method,
        public Headers $headers,
        public array $query,
        public array $path,
        public string $body,
        public array $form = [],
        public array $files = [],
        public bool $formDiscarded = false,
    ) {}

    /**
     * a `Content-Length` other than 0 or a `Transfer-Encoding` signals a body (RFC 9112 6);
     * bytes, fields or files count too, since a request built in code carries neither field
     */
    public function hasContent(): bool
    {
        $length = $this->headers->line(FieldName::CONTENT_LENGTH);

        return ($length !== null && trim($length, "0 \t") !== '')
            || $this->headers->has(FieldName::TRANSFER_ENCODING)
            || $this->body !== ''
            || $this->form !== []
            || $this->files !== [];
    }
}
