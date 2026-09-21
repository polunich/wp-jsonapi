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

namespace Polunich\WpJsonApi\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use ReflectionEnum;

/**
 * @internal
 */
#[CoversClass(ClientIdPolicy::class)]
final class ClientIdPolicyTest extends TestCase
{
    public function testTheCasesAreTheThreePolicies(): void
    {
        self::assertSame(
            ['Forbidden' => 'forbidden', 'Allowed' => 'allowed', 'Required' => 'required'],
            array_combine(
                array_map(static function (ClientIdPolicy $policy): string {
                    return $policy->name;
                }, ClientIdPolicy::cases()),
                array_map(static function (ClientIdPolicy $policy): string {
                    return $policy->value;
                }, ClientIdPolicy::cases()),
            ),
        );
    }

    /**
     * a declaration sets the policy through `ResourceType::clientIds()`,
     * so no public signature names the enum
     */
    public function testTheEnumIsInternal(): void
    {
        self::assertStringContainsString(
            '@internal',
            (string) (new ReflectionEnum(ClientIdPolicy::class))->getDocComment(),
        );
    }
}
