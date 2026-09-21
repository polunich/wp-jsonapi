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

namespace Polunich\WpJsonApi\Tests\Unit\Declaration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Declaration\QueryParameters;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Operation\OperationKind;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(QueryParameters::class)]
final class QueryParametersTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(QueryParameters::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testAParameterIsAddedAfterTheOnesDeclaredBefore(): void
    {
        $nearBy = new QueryParameterMetadata('nearBy', new StringCodec(), false);
        $minAge = new QueryParameterMetadata('minAge', new StringCodec(), true);

        self::assertSame(
            [$nearBy, $minAge],
            QueryParameters::added([$nearBy], $minAge, 'type "pets"', OperationKind::FetchCollection),
        );
    }

    public function testANameDeclaredAgainForAKindIsRefused(): void
    {
        $nearBy = new QueryParameterMetadata('nearBy', new StringCodec(), false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares the query parameter "nearBy" for fetch_collection twice,'
            . ' and each part of a declaration is written once');

        QueryParameters::added([$nearBy], $nearBy, 'type "pets"', OperationKind::FetchCollection);
    }

    public function testANameDeclaredAgainForAnEndpointNamesNoKind(): void
    {
        $nearBy = new QueryParameterMetadata('nearBy', new StringCodec(), false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "nearPets" declares the query parameter "nearBy" twice, and each'
            . ' part of a declaration is written once');

        QueryParameters::added([$nearBy], $nearBy, 'the endpoint "nearPets"');
    }
}
