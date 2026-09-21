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

namespace Polunich\WpJsonApi\Tests\Unit\OpenApi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\OpenApi\SecurityBuilder;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(SecurityBuilder::class)]
final class SecurityBuilderTest extends TestCase
{
    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(SecurityBuilder::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testTheSchemesAreWrittenUnderTheirNamesInDeclarationOrder(): void
    {
        $builder = new SecurityBuilder(self::api([
            SecurityScheme::applicationPasswords(),
            new SecurityScheme('bearer', ['type' => 'http', 'scheme' => 'bearer']),
        ]));

        self::assertSame(
            '{"applicationPasswords":{"type":"http","scheme":"basic"},"bearer":{"type":"http","scheme":"bearer"}}',
            self::json($builder->securitySchemes()),
        );
    }

    public function testTheSecurityListsEachSchemeAndThenTheEmptyRequirement(): void
    {
        $builder = new SecurityBuilder(self::api([
            SecurityScheme::applicationPasswords(),
            new SecurityScheme('bearer', ['type' => 'http', 'scheme' => 'bearer']),
        ]));

        self::assertSame(
            '[{"applicationPasswords":[]},{"bearer":[]},{}]',
            self::json($builder->security()),
        );
    }

    public function testTheComponentsHoldTheSchemesAnOperationDeclaresBesideThoseOfTheApi(): void
    {
        $signature = new SecurityScheme('signature', ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Signature']);
        $builder = new SecurityBuilder(self::api([SecurityScheme::applicationPasswords()], [Samples::endpoint(
            'stats',
            'GET',
            '/stats',
            [],
            responses: [204 => new ResponseMetadata(ResponseShapeKind::NoContent, null, null, null, null, null)],
            securitySchemes: [$signature],
        )]));

        self::assertSame(
            '{"applicationPasswords":{"type":"http","scheme":"basic"},'
            . '"signature":{"type":"apiKey","in":"header","name":"X-Signature"}}',
            self::json($builder->securitySchemes()),
        );
        self::assertSame('[{"applicationPasswords":[]},{}]', self::json($builder->security()));
    }

    public function testTheRequirementsOfAnyListEndWithTheEmptyOne(): void
    {
        self::assertSame(
            '[{"restNonce":[]},{}]',
            self::json(SecurityBuilder::requirements([SecurityScheme::restNonce()])),
        );
        self::assertSame('[{}]', self::json(SecurityBuilder::requirements([])));
    }

    public function testAnApiWithoutASchemeOffersAnonymousAccessAlone(): void
    {
        $builder = new SecurityBuilder(self::api([]));

        self::assertSame('{}', self::json($builder->securitySchemes()));
        self::assertSame('[{}]', self::json($builder->security()));
    }

    public function testANameOfDigitsStaysAMemberOfTheObjects(): void
    {
        $builder = new SecurityBuilder(self::api([new SecurityScheme('0', ['type' => 'http', 'scheme' => 'basic'])]));

        self::assertSame('{"0":{"type":"http","scheme":"basic"}}', self::json($builder->securitySchemes()));
        self::assertSame('[{"0":[]},{}]', self::json($builder->security()));
    }

    /**
     * @param list<SecurityScheme>   $schemes
     * @param list<EndpointMetadata> $endpoints
     */
    private static function api(array $schemes, array $endpoints = []): ApiMetadata
    {
        return new ApiMetadata(
            'petstore',
            '1.0',
            'acme/v1',
            new ResourceRegistry([]),
            $endpoints,
            [],
            Samples::operators(),
            $schemes,
            null,
            Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
