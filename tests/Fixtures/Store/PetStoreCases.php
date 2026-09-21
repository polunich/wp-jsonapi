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

namespace Polunich\WpJsonApi\Tests\Fixtures\Store;

use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Store\ResourceNotFoundException;
use Polunich\WpJsonApi\Store\VersionMismatchException;
use Throwable;

/**
 * @internal
 */
final readonly class PetStoreCases
{
    /**
     * each precondition with the parent it guards
     * and the throwable the write ends in, null for none;
     * pet 1 is stored at version 1, pet 9 does not exist
     *
     * @return iterable<string, array{?Precondition, string, class-string<Throwable>|null}>
     */
    public static function preconditions(): iterable
    {
        yield 'no precondition' => [null, '1', null];
        yield 'any version' => [Precondition::anyVersion(), '1', null];
        yield 'a list that holds the stored version' => [Precondition::listedVersions(['0', '1']), '1', null];
        yield 'a list without the stored version' => [
            Precondition::listedVersions(['2']),
            '1',
            VersionMismatchException::class,
        ];
        yield 'a parent that is gone, without precondition' => [null, '9', ResourceNotFoundException::class];
        yield 'a parent that is gone, under any version' => [
            Precondition::anyVersion(),
            '9',
            ResourceNotFoundException::class,
        ];
        yield 'a parent that is gone, under a list' => [
            Precondition::listedVersions(['1']),
            '9',
            ResourceNotFoundException::class,
        ];
    }

    /**
     * @param list<StoredPet> $pets
     *
     * @return list<string>
     */
    public static function ids(array $pets): array
    {
        return array_map(static function (StoredPet $pet): string {
            return $pet->id;
        }, $pets);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Identifier>
     */
    public static function identifiers(array $ids): array
    {
        return array_map(static function (string $id): Identifier {
            return new Identifier(PetStore::TYPE, $id);
        }, $ids);
    }
}
