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
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Metadata\DeclaredQueryParameter;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Operation\OperationKind;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(DeclaredQueryParameter::class)]
final class DeclaredQueryParameterTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(DeclaredQueryParameter::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testAParameterOfARouteIsReadOnTheRouteOfItsKind(): void
    {
        $declared = new DeclaredQueryParameter(self::parameter(), 'type "pets"', OperationKind::FetchCollection);

        self::assertSame('the fetch_collection route of type "pets"', $declared->place());
    }

    public function testAParameterOfAnEndpointIsReadOnTheEndpoint(): void
    {
        $declared = new DeclaredQueryParameter(self::parameter(), 'the endpoint "adoptPet"', null);

        self::assertSame('the endpoint "adoptPet"', $declared->place());
    }

    private static function parameter(): QueryParameterMetadata
    {
        return new QueryParameterMetadata('nearBy', new StringCodec(), false);
    }
}
