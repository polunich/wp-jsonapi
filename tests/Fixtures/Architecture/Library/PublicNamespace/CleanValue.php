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

namespace Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace;

use DateTimeImmutable;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\DerivedException;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ListedValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\PublicBaseException;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\Tests\UnmarkedInTheExcludedRoot;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Outside\InternalOutsideTheRoot;

/**
 * a public class that names nothing but public API and types outside the library,
 * in every place the check reads and in the type argument of its interface:
 * a class of the public namespace, a listed class, a subclass of a public base class,
 * a class below the excluded root, a tagged class outside the root, and PHP classes;
 * the check reports nothing for it
 *
 * @template T of PublicBaseException
 *
 * @implements Tagged<ListedValue>
 */
final class CleanValue implements Tagged
{
    public const DEFAULT = Mode::On;

    /**
     * @param list<Mode> $modes
     */
    public function __construct(
        public ListedValue $value,
        public array $modes = [],
        public ?InternalOutsideTheRoot $outside = null,
    ) {}

    /**
     * @template U of UnmarkedInTheExcludedRoot
     *
     * @param U $excluded
     *
     * @return list<U>
     *
     * @throws DerivedException when the moment lies before the epoch
     */
    public function values(object $excluded, DateTimeImmutable $at): array
    {
        if ($at->getTimestamp() < 0) {
            throw new DerivedException();
        }

        return [$excluded];
    }

    /**
     * @return Tagged<ListedValue>
     */
    protected function tagged(): Tagged
    {
        return $this;
    }
}
