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

namespace Polunich\WpJsonApi\Codec;

use RuntimeException;
use Throwable;

/**
 * thrown by the `$toValue` closure of a `MappedCodec` to reject a decoded value;
 * it becomes a violation with this code and these parameters at the pointer being decoded,
 * or at the member `$path` leads to, so a rule between members points at the one to correct.
 *
 * the property is `$violationCode`, not `$code`: `Exception::$code` is an int
 */
final class RejectedValueException extends RuntimeException
{
    /**
     * @param string                $violationCode a stable lower_snake code
     * @param array<string, scalar> $parameters    the values the message of the code names
     * @param list<string>          $path          the reference tokens from the decoded value
     *                                             down to the member at fault, such as `['end']`
     */
    public function __construct(
        private readonly string $violationCode = ViolationCode::ValueRejected->value,
        private readonly array $parameters = [],
        private readonly array $path = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct('the decoded value was rejected: ' . $violationCode, 0, $previous);
    }

    public function violationCode(): string
    {
        return $this->violationCode;
    }

    /**
     * @return array<string, scalar>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * @return list<string>
     */
    public function path(): array
    {
        return $this->path;
    }
}
