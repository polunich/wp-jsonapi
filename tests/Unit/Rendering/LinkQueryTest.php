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

namespace Polunich\WpJsonApi\Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Rendering\LinkQuery;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(LinkQuery::class)]
final class LinkQueryTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(LinkQuery::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testALinkToARouteKeepsWhatTheRouteReadsAndTheLanguageParameters(): void
    {
        $query = self::linkQuery()->to(self::fetchResource(), self::request([
            'fields' => ['pets' => 'name'],
            'previewMode' => 'on',
            'lang' => 'uk',
            'include' => 'owner',
        ]));

        self::assertSame(
            ['fields' => ['pets' => 'name'], 'previewMode' => 'on', 'lang' => 'uk', 'include' => 'owner'],
            $query,
        );
    }

    public function testALinkToARouteLeavesOutAParameterTheRouteDoesNotRead(): void
    {
        $query = self::linkQuery()->to(self::fetchResource(), self::request([
            'notifyOwner' => 'true',
            'sort' => 'name',
            'include' => 'owner',
        ]));

        self::assertSame(['include' => 'owner'], $query);
    }

    public function testALinkCarriesNoParameterWordPressConsumes(): void
    {
        $query = self::linkQuery()->to(self::fetchResource(), self::request([
            'rest_route' => '/acme/v1/pets/1',
            '_method' => 'DELETE',
            '_wpnonce' => 'abc123',
        ]));

        self::assertSame([], $query);
    }

    public function testALinkToAnEndpointKeepsWhatTheEndpointReads(): void
    {
        $endpoint = Samples::endpoint(queryParameters: [
            new QueryParameterMetadata('adoptionNote', new StringCodec(), false),
        ]);

        self::assertSame(
            ['adoptionNote' => 'gently'],
            self::linkQuery()->toEndpoint($endpoint, self::request(['adoptionNote' => 'gently', '_method' => 'PUT'])),
        );
    }

    public function testALinkToAnotherResourceCarriesTheDecidingLanguageParameterAlone(): void
    {
        self::assertSame(
            ['lang' => 'uk'],
            self::linkQuery()->language(self::request(['lang' => 'uk', 'include' => 'owner'])),
        );
    }

    private static function linkQuery(): LinkQuery
    {
        return new LinkQuery(
            new ResourceRegistry([
                Samples::resource(
                    'pets',
                    relationships: [Samples::toOne('owner', 'customers')],
                    capabilities: [Capability::ResourceUpdater],
                    queryParameters: [
                        OperationKind::FetchResource->value => [
                            new QueryParameterMetadata('previewMode', new StringCodec(), false),
                        ],
                        OperationKind::UpdateResource->value => [
                            new QueryParameterMetadata('notifyOwner', new StringCodec(), false),
                        ],
                    ],
                ),
                Samples::resource('customers'),
            ]),
            new LanguageSources([
                LanguageSourceMetadata::queryParameter('lang'),
                LanguageSourceMetadata::acceptLanguage(),
            ]),
        );
    }

    private static function fetchResource(): OperationRoute
    {
        return new OperationRoute(
            OperationKind::FetchResource,
            'pets',
            null,
            new PathParameter('id', new DigitsCodec()),
            ['pets'],
        );
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function request(array $query): ServerRequest
    {
        return new ServerRequest('PATCH', new Headers([]), $query, [], '');
    }
}
