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
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Metadata\RouteSecuritySchemes;
use Polunich\WpJsonApi\Operation\OperationKind;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(RouteSecuritySchemes::class)]
final class RouteSecuritySchemesTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(RouteSecuritySchemes::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testAListPerEnabledKindOfTheOwnRoutesPasses(): void
    {
        self::check([
            OperationKind::FetchResource->value => [SecurityScheme::restNonce()],
            OperationKind::FetchCollection->value => [],
        ], true);

        $this->addToAssertionCount(1);
    }

    public function testAKindOfAnotherOwnersRoutesIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'type "pets" declares security schemes for fetch_related, which is no route of its own: elsewhere',
        );

        self::check([OperationKind::FetchRelated->value => []], true);
    }

    public function testAKindTheDeclarationDoesNotEnableIsRefusedEvenWithAnEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares security schemes for fetch_resource, which it does not'
            . ' enable');

        self::check([OperationKind::FetchResource->value => []], false);
    }

    public function testASchemeNamedTwiceForOneKindIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" for fetch_resource declares the security scheme "restNonce" twice');

        $nonce = SecurityScheme::restNonce();

        self::check([OperationKind::FetchResource->value => [$nonce, $nonce]], true);
    }

    /**
     * @param array<value-of<OperationKind>, list<SecurityScheme>> $securitySchemes
     */
    private static function check(array $securitySchemes, bool $enabled): void
    {
        RouteSecuritySchemes::check(
            $securitySchemes,
            [OperationKind::FetchCollection, OperationKind::FetchResource],
            static function () use ($enabled): bool {
                return $enabled;
            },
            'type "pets"',
            'elsewhere',
        );
    }
}
