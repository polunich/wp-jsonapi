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
use Polunich\WpJsonApi\Text\Printable;
use RuntimeException;
use Throwable;

/**
 * a failed request: the status of its response and the problems its error document lists.
 *
 * the class is concrete and open:
 * `new JsonApiException(410, $descriptions)` carries a status without a subclass of its own,
 * and a project may extend it.
 *
 * at least one description is required, as an error document without one describes no failure.
 * the message is written for the log and never reaches the client;
 * the int code of `\Exception` stays 0, because `status()` is the status
 */
class JsonApiException extends RuntimeException
{
    /**
     * @var non-empty-list<ErrorDescription>
     */
    private readonly array $descriptions;

    /**
     * @param list<ErrorDescription> $descriptions in the order the error document lists them
     *
     * @throws InvalidArgumentException when the status is not 4xx or 5xx,
     *                                  or no description is given
     */
    public function __construct(
        private readonly int $status,
        array $descriptions,
        ?Throwable $previous = null,
    ) {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException(
                'a JSON:API exception carries a 4xx or 5xx status code, got ' . $status,
            );
        }

        if ($descriptions === []) {
            throw new InvalidArgumentException('a JSON:API exception carries at least one error description');
        }

        $this->descriptions = $descriptions;

        parent::__construct(self::describe($status, $descriptions), 0, $previous);
    }

    /**
     * a 401 is built without challenges.
     * a status without a subclass keeps its exact value rather than the x00 status of its class,
     * since "a client MUST understand the class of any status code" (RFC 9110 15)
     *
     * @param list<ErrorDescription> $descriptions
     *
     * @throws InvalidArgumentException when the status is not 4xx or 5xx,
     *                                  or no description is given
     */
    final public static function ofStatus(int $status, array $descriptions, ?Throwable $previous = null): self
    {
        return match ($status) {
            400 => new BadRequestException($descriptions, $previous),
            401 => new UnauthorizedException($descriptions, [], $previous),
            403 => new ForbiddenException($descriptions, $previous),
            404 => new NotFoundException($descriptions, $previous),
            406 => new NotAcceptableException($descriptions, $previous),
            409 => new ConflictException($descriptions, $previous),
            412 => new PreconditionFailedException($descriptions, $previous),
            413 => new ContentTooLargeException($descriptions, $previous),
            415 => new UnsupportedMediaTypeException($descriptions, $previous),
            422 => new UnprocessableContentException($descriptions, $previous),
            428 => new PreconditionRequiredException($descriptions, $previous),
            500 => new InternalServerErrorException($descriptions, $previous),
            default => new self($status, $descriptions, $previous),
        };
    }

    final public function status(): int
    {
        return $this->status;
    }

    /**
     * @return non-empty-list<ErrorDescription>
     */
    final public function descriptions(): array
    {
        return $this->descriptions;
    }

    /**
     * @param non-empty-list<ErrorDescription> $descriptions
     */
    private static function describe(int $status, array $descriptions): string
    {
        $described = [];

        foreach ($descriptions as $description) {
            $code = $description->code instanceof ErrorCode ? $description->code->value : $description->code;
            $text = Printable::of($code);

            if ($description->pointer !== null) {
                $text .= ' at pointer "' . Printable::of($description->pointer) . '"';
            } elseif ($description->parameter !== null) {
                $text .= ' at parameter "' . Printable::of($description->parameter) . '"';
            } elseif ($description->header !== null) {
                $text .= ' at header "' . Printable::of($description->header) . '"';
            }

            if ($description->status !== null) {
                $text .= ' with status ' . $description->status;
            }

            $described[] = $text;
        }

        return 'the request failed with status ' . $status . ': ' . implode(', ', $described);
    }
}
