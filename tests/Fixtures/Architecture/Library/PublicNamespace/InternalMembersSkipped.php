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

use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalState;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalValue;

/**
 * a public class whose members name types that are not public API,
 * each member marked `@internal` or private:
 * no such member is public API, so the class leaks nothing
 */
final class InternalMembersSkipped
{
    /**
     * @internal
     */
    public const DEFAULT = InternalState::On;

    private const FALLBACK = InternalState::Off;

    /**
     * @internal
     */
    public ?InternalValue $value = null;

    private ?InternalValue $kept = null;

    /**
     * @internal
     */
    public function swap(InternalValue $value): ?InternalValue
    {
        return $this->remember($value);
    }

    /**
     * @internal
     */
    public function fallback(): InternalState
    {
        return self::FALLBACK;
    }

    private function remember(InternalValue $value): ?InternalValue
    {
        $kept = $this->kept;
        $this->kept = $value;

        return $kept;
    }
}
