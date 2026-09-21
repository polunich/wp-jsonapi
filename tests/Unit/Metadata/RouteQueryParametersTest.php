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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RouteQueryParameters;
use Polunich\WpJsonApi\Operation\OperationKind;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(RouteQueryParameters::class)]
final class RouteQueryParametersTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(RouteQueryParameters::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testParametersOfTheOwnEnabledKindsPass(): void
    {
        self::check([
            OperationKind::FetchCollection->value => [self::parameter('nearBy'), self::parameter('minAge')],
            OperationKind::FetchResource->value => [self::parameter('nearBy')],
        ]);

        $this->addToAssertionCount(1);
    }

    public function testAKindOfAnotherRouteIsRefusedWithWhereItIsDeclared(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares a query parameter for delete_resource, which is no route of'
            . ' its own: elsewhere');

        self::check([OperationKind::DeleteResource->value => [self::parameter('hard')]]);
    }

    public function testAKindThatIsNotEnabledIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares the query parameter "preview" for fetch_resource, which it'
            . ' does not enable');

        self::check([OperationKind::FetchResource->value => [self::parameter('preview')]], false);
    }

    public function testANameTwiceForOneKindIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares the query parameter "nearBy" for fetch_collection twice');

        self::check([OperationKind::FetchCollection->value => [self::parameter('nearBy'), self::parameter('nearBy')]]);
    }

    public function testARouteTheDocumentsDoNotLinkToMayRequireAParameter(): void
    {
        self::check([OperationKind::FetchCollection->value => [self::required('nearBy')]]);

        $this->addToAssertionCount(1);
    }

    public function testTheFetchOfOneResourceRequiresNoParameter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" requires the query parameter "preview" for fetch_resource, and a'
            . ' document links to that route with no query parameter of its own, so every such link would get'
            . ' 400');

        self::check([OperationKind::FetchResource->value => [self::required('preview')]]);
    }

    #[DataProvider('linkedReadsOfARelationship')]
    public function testAReadOfARelationshipRequiresNoParameter(OperationKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires the query parameter "since" for ' . $kind->value);

        RouteQueryParameters::check(
            [$kind->value => [self::required('since')]],
            [$kind],
            static function (): bool {
                return true;
            },
            'relationship "orders"',
            'elsewhere',
        );
    }

    /**
     * @return iterable<string, array{OperationKind}>
     */
    public static function linkedReadsOfARelationship(): iterable
    {
        yield 'the related route' => [OperationKind::FetchRelated];
        yield 'the relationship route' => [OperationKind::FetchRelationship];
    }

    public function testAWriteMayRequireAParameter(): void
    {
        RouteQueryParameters::checkLinked(OperationKind::UpdateResource, self::required('notify'), 'type "pets"');

        $this->addToAssertionCount(1);
    }

    /**
     * the declaration owns the collection and the resource route, and enables them as asked
     *
     * @param array<value-of<OperationKind>, list<QueryParameterMetadata>> $queryParameters
     */
    private static function check(array $queryParameters, bool $enabled = true): void
    {
        RouteQueryParameters::check(
            $queryParameters,
            [OperationKind::FetchCollection, OperationKind::FetchResource],
            static function (OperationKind $kind) use ($enabled): bool {
                return $enabled;
            },
            'type "pets"',
            'elsewhere',
        );
    }

    private static function parameter(string $name): QueryParameterMetadata
    {
        return new QueryParameterMetadata($name, new StringCodec(), false);
    }

    private static function required(string $name): QueryParameterMetadata
    {
        return new QueryParameterMetadata($name, new StringCodec(), true);
    }
}
