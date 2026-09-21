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

namespace Polunich\WpJsonApi\Metadata;

use InvalidArgumentException;
use Polunich\WpJsonApi\Operation\OperationKind;

/**
 * the kinds whose routes a declaration excludes, out of the kinds its owner may name:
 * `only()` names the kinds that keep their route, `except()` the kinds that lose it,
 * as Laravel's partial resource routes do.
 *
 * an excluded route stays registered, and a request to it gets 403
 * as to a kind the declaration does not enable; no link leads to it.
 * an endpoint may take the place of an excluded read route, links included
 *
 * @internal
 */
final readonly class RouteExclusion
{
    /**
     * @param list<OperationKind> $kinds the excluded kinds, in the order of `RouteOwner::kinds()`
     */
    private function __construct(
        public RouteOwner $owner,
        public array $kinds,
    ) {}

    public static function none(RouteOwner $owner): self
    {
        return new self($owner, []);
    }

    /**
     * @param non-empty-list<OperationKind> $kept
     * @param string                        $declaration names the declaration in a message,
     *                                                   such as `type "pets"`
     *
     * @throws InvalidArgumentException when a kind is none the owner may name, or is named twice
     */
    public static function only(RouteOwner $owner, array $kept, string $declaration): self
    {
        self::check($owner, $kept, $declaration, 'only()');

        return new self($owner, array_values(array_filter(
            $owner->kinds(),
            static function (OperationKind $kind) use ($kept): bool {
                return !in_array($kind, $kept, true);
            },
        )));
    }

    /**
     * @param non-empty-list<OperationKind> $excluded
     * @param string                        $declaration names the declaration in a message,
     *                                                   such as `type "pets"`
     *
     * @throws InvalidArgumentException when a kind is none the owner may name, or is named twice
     */
    public static function except(RouteOwner $owner, array $excluded, string $declaration): self
    {
        self::check($owner, $excluded, $declaration, 'except()');

        return new self($owner, array_values(array_filter(
            $owner->kinds(),
            static function (OperationKind $kind) use ($excluded): bool {
                return in_array($kind, $excluded, true);
            },
        )));
    }

    public function excludes(OperationKind $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }

    public function excludesAll(): bool
    {
        return $this->kinds === $this->owner->kinds();
    }

    /**
     * @param list<OperationKind> $kinds
     *
     * @throws InvalidArgumentException when a kind is none the owner may name, or is named twice
     */
    private static function check(RouteOwner $owner, array $kinds, string $declaration, string $call): void
    {
        $scope = $owner->kinds();
        $seen = [];

        foreach ($kinds as $kind) {
            if (!in_array($kind, $scope, true)) {
                throw new InvalidArgumentException(sprintf(
                    '%s names %s in %s, and it names only %s there: %s',
                    $declaration,
                    $kind->value,
                    $call,
                    implode(', ', array_map(static function (OperationKind $allowed): string {
                        return $allowed->value;
                    }, $scope)),
                    $owner->elsewhere(),
                ));
            }

            if (array_key_exists($kind->value, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    '%s names %s twice in %s, and each kind is named once',
                    $declaration,
                    $kind->value,
                    $call,
                ));
            }

            $seen[$kind->value] = true;
        }
    }
}
