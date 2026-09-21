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

namespace Polunich\WpJsonApi\WordPress;

use InvalidArgumentException;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Http\FieldValueGrammar;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\Method;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Http\UploadedFile;
use WP_REST_Request;

/**
 * the library request a WordPress request carries.
 *
 * it calls only accessors that return stored properties, never `get_params()`,
 * which parses the body, runs a filter and reads an option;
 * the library parses the query and the body itself.
 *
 * - the method is the one the route was matched with: upper case,
 *   after `_method` and `X-HTTP-Method-Override` were applied.
 * - WordPress spells a field name in lower case with `_` for `-`, which `Http\Headers` reads as is.
 *   it takes the names from the `HTTP_` keys of `$_SERVER`, which a server may spell in any way,
 *   so a name that is no token is left out, not refused.
 * - a route parameter is percent-decoded once,
 *   since every way a site routes a request hands it over encoded once:
 *   pretty permalinks match the path as it was sent;
 *   a link of the API encodes a path in the `rest_route` query twice, and PHP decodes it once;
 *   `index.php/` permalinks read the decoded `PATH_INFO` and encode its `%` again,
 *   so an encoded `/` of an id is a separator there by then.
 * - an `application/x-www-form-urlencoded` body is parsed from its bytes with `parse_str()`,
 *   whatever the method: WordPress parses a non-POST body only when a parameter is first asked for,
 *   so its body parameters would depend on who asked first.
 * - a `multipart/form-data` body has no bytes in PHP;
 *   its fields and files are the ones PHP stored for a POST, which WordPress hands over as is.
 *   a POST form larger than `post_max_size` loses both,
 *   "the $_POST and $_FILES superglobals are empty" (php.net),
 *   and the request says it was discarded
 *
 * @internal
 */
final readonly class RequestFactory
{
    /** the octets of a POST that PHP reads into a form; 0 for no limit */
    private int $postMaxSize;

    /**
     * @param ?int $postMaxSize null reads `post_max_size` of the running PHP
     */
    public function __construct(?int $postMaxSize = null)
    {
        $this->postMaxSize = $postMaxSize ?? ini_parse_quantity((string) ini_get('post_max_size'));
    }

    public function fromWordPress(WP_REST_Request $request): ServerRequest
    {
        $headers = new Headers(self::fields($request->get_headers()));
        // null for a request built in code, whatever the accessor's docblock says
        $body = (string) $request->get_body();
        $line = $headers->line(FieldName::CONTENT_TYPE);
        $mediaType = $line === null ? null : MediaType::parse($line);
        $form = [];
        $files = [];
        $discarded = false;

        if ($mediaType !== null && $mediaType->essence() === MediaType::FORM_URLENCODED) {
            parse_str($body, $form);
        } elseif ($mediaType !== null && $mediaType->essence() === MediaType::MULTIPART_FORM_DATA) {
            $form = $request->get_body_params();
            $files = self::files($request->get_file_params());
        }

        if ($request->get_method() === Method::POST && $mediaType !== null) {
            $discarded = in_array(
                $mediaType->essence(),
                [MediaType::FORM_URLENCODED, MediaType::MULTIPART_FORM_DATA],
                true,
            )
                && $this->exceedsPostMaxSize($headers->line(FieldName::CONTENT_LENGTH));
        }

        return new ServerRequest(
            $request->get_method(),
            $headers,
            $request->get_query_params(),
            self::routeParameters($request->get_url_params()),
            $body,
            $form,
            $files,
            $discarded,
        );
    }

    private function exceedsPostMaxSize(?string $length): bool
    {
        // a cast of more digits than an int holds returns PHP_INT_MAX, still above any limit
        return $this->postMaxSize > 0 && $length !== null && ctype_digit($length) && (int) $length > $this->postMaxSize;
    }

    /**
     * PHP writes the files of a bracketed name as parallel arrays,
     * one per key such as `name`, `tmp_name` or `error`;
     * the request holds one `UploadedFile` per file instead, nested as the brackets were.
     * an entry of another shape is left out
     *
     * @param array<array-key, mixed> $files
     *
     * @return array<array-key, mixed>
     */
    private static function files(array $files): array
    {
        $read = [];

        foreach ($files as $field => $file) {
            if (!is_array($file)) {
                continue;
            }

            $entry = self::file(
                $file['name'] ?? null,
                $file['type'] ?? null,
                $file['size'] ?? null,
                $file['tmp_name'] ?? null,
                $file['error'] ?? null,
            );

            if ($entry !== null) {
                $read[$field] = $entry;
            }
        }

        return $read;
    }

    /**
     * @return UploadedFile|array<array-key, mixed>|null
     */
    private static function file(
        mixed $name,
        mixed $type,
        mixed $size,
        mixed $path,
        mixed $error,
    ): UploadedFile|array|null {
        if (is_array($name)) {
            $nested = [];

            foreach ($name as $key => $value) {
                $entry = self::file(
                    $value,
                    is_array($type) ? ($type[$key] ?? null) : null,
                    is_array($size) ? ($size[$key] ?? null) : null,
                    is_array($path) ? ($path[$key] ?? null) : null,
                    is_array($error) ? ($error[$key] ?? null) : null,
                );

                if ($entry !== null) {
                    $nested[$key] = $entry;
                }
            }

            return $nested;
        }

        if (!is_string($name) || !is_int($error) || !is_int($size)) {
            return null;
        }

        try {
            return new UploadedFile($name, is_string($type) ? $type : '', $size, is_string($path) ? $path : '', $error);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $headers
     *
     * @return array<string, list<string>>
     */
    private static function fields(array $headers): array
    {
        $fields = [];

        foreach ($headers as $name => $values) {
            if (!is_string($name) || !FieldValueGrammar::isToken($name)) {
                continue;
            }

            $lines = [];

            foreach (is_array($values) ? $values : [$values] as $value) {
                if (is_string($value)) {
                    $lines[] = $value;
                }
            }

            $fields[$name] = $lines;
        }

        return $fields;
    }

    /**
     * @param array<array-key, mixed> $parameters
     *
     * @return array<string, string>
     */
    private static function routeParameters(array $parameters): array
    {
        $decoded = [];

        foreach ($parameters as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $decoded[$name] = rawurldecode($value);
            }
        }

        return $decoded;
    }
}
