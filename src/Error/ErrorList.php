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

namespace Polunich\WpJsonApi\Error;

use InvalidArgumentException;

/**
 * collects every problem of one step of a request and returns them as one exception,
 * so a client learns all mistakes of that step from one response
 *
 * @internal
 */
final class ErrorList
{
    /**
     * @var list<JsonApiException>
     */
    private array $exceptions = [];

    /**
     * @var list<int>
     */
    private array $statuses = [];

    public function __construct(private readonly StatusSelector $statusSelector) {}

    /**
     * a 401 is refused: the challenges it carries are known only to authorization
     *
     * @throws InvalidArgumentException when the status is 401 or not 4xx
     */
    public function add(JsonApiException $exception): void
    {
        $status = $exception->status();

        if ($status === 401) {
            throw new InvalidArgumentException(
                'an error list refuses a 401: its challenges come from authorization, never from parsing',
            );
        }

        if ($status < 400 || $status > 499) {
            throw new InvalidArgumentException(
                'an error list collects request errors, whose status is a 4xx other than 401, got ' . $status,
            );
        }

        $this->exceptions[] = $exception;
        $this->statuses[] = $status;
    }

    /**
     * an error object carries "the HTTP status code applicable to this problem"
     * (JSON:API, Error Objects): a description without a status of its own
     * takes the status of its exception when that differs from the merged status
     */
    public function exception(): ?JsonApiException
    {
        if ($this->exceptions === []) {
            return null;
        }

        if (count($this->exceptions) === 1) {
            return $this->exceptions[0];
        }

        $status = $this->statusSelector->select($this->statuses);
        $descriptions = [];

        foreach ($this->exceptions as $exception) {
            foreach ($exception->descriptions() as $description) {
                $descriptions[] = $description->status === null && $exception->status() !== $status
                    ? $description->withStatus($exception->status())
                    : $description;
            }
        }

        return JsonApiException::ofStatus($status, $descriptions);
    }

    /**
     * @throws JsonApiException the one exception of `exception()`
     */
    public function throwIfAny(): void
    {
        $exception = $this->exception();

        if ($exception !== null) {
            throw $exception;
        }
    }
}
