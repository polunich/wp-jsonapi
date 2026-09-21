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

namespace Polunich\WpJsonApi\Tests\Fixtures\Routing;

use Override;
use Polunich\WpJsonApi\Routing\UrlGenerator;

/**
 * plain paths below a root, with the query appended,
 * so an assertion reads the links a class writes and not the URL rules of a site.
 *
 * `httpQuery()` appends the query as `http_build_query()` writes it, as a site does;
 * `jsonQuery()` appends it as JSON text, so an assertion reads the members a link carries
 * and tells an int the library wrote from the text the client sent
 *
 * @internal
 */
final readonly class PlainUrls implements UrlGenerator
{
    private function __construct(private string $root, private bool $json) {}

    public static function httpQuery(string $root = ''): self
    {
        return new self($root, false);
    }

    public static function jsonQuery(): self
    {
        return new self('', true);
    }

    /**
     * @param non-empty-list<string>  $segments
     * @param array<array-key, mixed> $query
     */
    #[Override]
    public function url(array $segments, array $query = []): string
    {
        $path = '/' . implode('/', $segments);

        if ($query === []) {
            return $this->root . $path;
        }

        return $this->root . $path . '?' . ($this->json ? (string) json_encode($query) : http_build_query($query));
    }
}
