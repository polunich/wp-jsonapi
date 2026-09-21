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

namespace Polunich\WpJsonApi\Tests\Integration;

use JsonException;
use LogicException;
use stdClass;

/**
 * what a client receives for one request sent through WordPress
 *
 * @internal
 */
final readonly class RestResponse
{
    /**
     * @param array<string, string> $headers each under its lower-case name
     * @param string                $body    empty where WordPress wrote none
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * member names and list positions in turn; null where the path leads nowhere
     */
    public function member(string|int ...$path): mixed
    {
        $value = $this->document();

        foreach ($path as $step) {
            if (is_int($step) && is_array($value)) {
                $value = $value[$step] ?? null;
            } elseif (is_string($step) && $value instanceof stdClass) {
                $value = $value->{$step} ?? null;
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * empty where the path leads to no list
     *
     * @return list<mixed>
     */
    public function members(string|int ...$path): array
    {
        $value = $this->member(...$path);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @throws JsonException  when the body is no JSON
     * @throws LogicException when it is no JSON object
     */
    public function document(): stdClass
    {
        $document = json_decode($this->body, false, 512, JSON_THROW_ON_ERROR);

        if (!$document instanceof stdClass) {
            throw new LogicException(sprintf('the body is no JSON object: %s', $this->body));
        }

        return $document;
    }
}
