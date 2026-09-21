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

namespace Polunich\WpJsonApi\Tests\Fixtures\WordPress;

use Override;
use WP_REST_Server;

/**
 * a `WP_REST_Server` that records the fields it is asked to send or remove
 * and the statuses it is asked to set,
 * instead of calling `header()`, `header_remove()` and `status_header()`:
 * a command line process sends no header field, and `header_remove()` warns once output started.
 * `http_response_code()` cannot report the status either:
 * once output started, it warns and sets nothing.
 *
 * it extends the `WP_REST_Server` of WordPress,
 * so it loads only after `CoreFiles::load()` or after WordPress itself
 *
 * @internal
 */
final class RecordingRestServer extends WP_REST_Server
{
    /** @var list<string> the names of the fields removed, in order */
    public array $removed = [];

    /**
     * @var list<array{string, ?string}> each field sent, name and value, and each field removed,
     *                                   with a null value, in order
     */
    public array $fields = [];

    /** @var list<int> the statuses set, in order */
    public array $statuses = [];

    /**
     * @param string $key
     * @param string $value
     *
     * @return void
     */
    #[Override]
    public function send_header($key, $value)
    {
        // as `WP_REST_Server::send_header()`, which folds the whitespace of a value
        $this->fields[] = [$key, (string) preg_replace('/\s+/', ' ', $value)];
    }

    /**
     * @param string $key
     *
     * @return void
     */
    #[Override]
    public function remove_header($key)
    {
        $this->removed[] = $key;
        $this->fields[] = [$key, null];
    }

    /**
     * what a client receives: a later field replaces an earlier one of the same name,
     * as `header()` does, and a removal drops it
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];

        foreach ($this->fields as [$name, $value]) {
            if ($value === null) {
                unset($headers[strtolower($name)]);

                continue;
            }

            $headers[strtolower($name)] = $value;
        }

        return $headers;
    }

    /**
     * @param int $code
     *
     * @return void
     */
    #[Override]
    protected function set_status($code)
    {
        $this->statuses[] = $code;
    }

    /**
     * the status set last, as a client receives it
     */
    public function status(): ?int
    {
        return $this->statuses === [] ? null : $this->statuses[array_key_last($this->statuses)];
    }
}
