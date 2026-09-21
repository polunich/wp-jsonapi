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

namespace Polunich\WpJsonApi\Tests\Fixtures\Architecture\CodeStyle;

use DateTimeImmutable;

/**
 * global classes written with a leading backslash, each reported on its line:
 * in each place PHP names a class,
 * and in the types of PHPDoc tags, `@template` included.
 * the rule leaves alone an imported class, a class of this namespace,
 * one of another namespace written in full, `\strlen()`, `\PHP_INT_MAX`,
 * and a class named in prose, like `\DateTimeImmutable` here.
 * `phpstan.neon.dist` ignores what the rule reports
 *
 * @template T of \Throwable
 *
 * @implements \IteratorAggregate<int, \DateTimeInterface>
 *
 * @internal
 */
#[\AllowDynamicProperties]
final class QualifiedNames implements \IteratorAggregate
{
    /** @var list<\DateTimeInterface> */
    private array $moments = [];

    /**
     * @param \DateTimeInterface $moment the moment to keep
     *
     * @return list<DateTimeImmutable|string>
     *
     * @throws \RuntimeException when the moment cannot be copied
     *
     * @phpstan-param \DateTimeInterface $moment
     */
    public function names(\DateTimeInterface $moment): array
    {
        try {
            $copy = \DateTimeImmutable::createFromInterface($moment);
        } catch (\Exception $exception) {
            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }
        $this->moments[] = $copy;

        return [
            $copy,
            new DateTimeImmutable('@' . \strlen((string) \PHP_INT_MAX)),
            $moment instanceof \DateTimeImmutable ? 'immutable' : 'mutable',
            \Polunich\WpJsonApi\Tests\Fixtures\Architecture\CodeStyle\ArrowFunctions::class,
            ArrowFunctions::class,
            \stdClass::class,
        ];
    }

    /**
     * @return \ArrayIterator<int, \DateTimeInterface>
     */
    #[\Override]
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->moments);
    }
}
