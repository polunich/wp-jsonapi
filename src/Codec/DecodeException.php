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

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class DecodeException extends RuntimeException
{
    /**
     * @var non-empty-list<Violation>
     */
    private readonly array $violations;

    /**
     * @param list<Violation> $violations
     */
    public function __construct(array $violations, ?Throwable $previous = null)
    {
        if ($violations === []) {
            throw new InvalidArgumentException('a decode exception must carry at least one violation');
        }

        $this->violations = $violations;

        parent::__construct(self::describe($violations), 0, $previous);
    }

    /**
     * @return non-empty-list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * the refusal of `encode()` for a value `decode()` refuses with these violations,
     * as the `InvalidArgumentException` `Codec::encode()` promises
     *
     * @param non-empty-list<Violation> $violations
     *
     * @internal
     */
    public static function encodeRefusal(array $violations, ?Throwable $previous = null): InvalidArgumentException
    {
        return new InvalidArgumentException(
            'the value is not one decode() returns, which refuses it: ' . self::listing($violations),
            0,
            $previous,
        );
    }

    /**
     * @param non-empty-list<Violation> $violations
     */
    private static function describe(array $violations): string
    {
        return 'the value could not be decoded: ' . self::listing($violations);
    }

    /**
     * @param non-empty-list<Violation> $violations
     */
    private static function listing(array $violations): string
    {
        $described = [];

        foreach ($violations as $violation) {
            $described[] = $violation->code . ' at "' . $violation->pointer->toString() . '"';
        }

        return implode(', ', $described);
    }
}
