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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\OpenApi\HeaderBuilder;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\OpenApi\SchemaMembers;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(HeaderBuilder::class)]
final class HeaderBuilderTest extends TestCase
{
    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(HeaderBuilder::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testTheResponseHeadersAreDescribedOnceUnderTheirOwnNames(): void
    {
        $headers = (new HeaderBuilder(self::petstore()))->headers();

        self::assertSame(
            [
                'Content-Language',
                'Vary',
                'ETag',
                'Location',
                'Content-Location',
                'WWW-Authenticate',
                'Accept',
                'Content-Disposition',
                'Content-Length',
            ],
            array_keys($headers),
        );

        foreach (['Location', 'Content-Location', 'WWW-Authenticate', 'Accept'] as $always) {
            self::assertTrue(SchemaMembers::object($headers, $always)->required);
        }

        foreach (['Content-Language', 'Vary', 'ETag'] as $sometimes) {
            self::assertObjectNotHasProperty('required', SchemaMembers::object($headers, $sometimes));
        }
    }

    /**
     * @param list<LanguageSourceMetadata> $sources
     */
    #[DataProvider('languageTexts')]
    public function testContentLanguageAndVaryAreWordedFromTheLanguageSources(
        array $sources,
        string $contentLanguage,
        string $vary,
    ): void {
        $headers = (new HeaderBuilder(self::languagePetstore(new LanguageSources($sources))))->headers();

        self::assertSame(
            $contentLanguage,
            SchemaMembers::text(SchemaMembers::object($headers, 'Content-Language'), 'description'),
        );
        self::assertSame($vary, SchemaMembers::text(SchemaMembers::object($headers, 'Vary'), 'description'));
    }

    /**
     * @return array<string, array{list<LanguageSourceMetadata>, string, string}>
     */
    public static function languageTexts(): array
    {
        return [
            'the field alone' => [
                [LanguageSourceMetadata::acceptLanguage()],
                'the language of the representation, chosen from the language ranges of Accept-Language [primary: RFC'
                . ' 9110 8.5]',
                'the request fields the selected representation depends on: Accept and Accept-Language [primary: RFC'
                . ' 9110 12.5.5]',
            ],
            'the parameter, then the field' => [
                [LanguageSourceMetadata::queryParameter('uiLocale'), LanguageSourceMetadata::acceptLanguage()],
                'the language of the representation, chosen from the language range of the query parameter uiLocale,'
                . ' else from the language ranges of Accept-Language [primary: RFC 9110 8.5]',
                'the request fields the selected representation depends on: Accept, and Accept-Language where the'
                . ' request does not carry the query parameter uiLocale [primary: RFC 9110 12.5.5]',
            ],
            'the field, then the parameter' => [
                [LanguageSourceMetadata::acceptLanguage(), LanguageSourceMetadata::queryParameter('uiLocale')],
                'the language of the representation, chosen from the language ranges of Accept-Language, else from the'
                . ' language range of the query parameter uiLocale [primary: RFC 9110 8.5]',
                'the request fields the selected representation depends on: Accept and Accept-Language [primary: RFC'
                . ' 9110 12.5.5]',
            ],
            'three parameters, then the field' => [
                [
                    LanguageSourceMetadata::queryParameter('uiLocale'),
                    LanguageSourceMetadata::queryParameter('langCode'),
                    LanguageSourceMetadata::queryParameter('ui-lang'),
                    LanguageSourceMetadata::acceptLanguage(),
                ],
                'the language of the representation, chosen from the language range of the query parameter uiLocale,'
                . ' else from the language range of the query parameter langCode, else from the language range of the'
                . ' query parameter ui-lang, else from the language ranges of Accept-Language [primary: RFC 9110 8.5]',
                'the request fields the selected representation depends on: Accept, and Accept-Language where the'
                . ' request does not carry the query parameter uiLocale, the query parameter langCode or the query'
                . ' parameter ui-lang [primary: RFC 9110 12.5.5]',
            ],
            'the parameter alone' => [
                [LanguageSourceMetadata::queryParameter('uiLocale')],
                'the language of the representation, chosen from the language range of the query parameter uiLocale'
                . ' [primary: RFC 9110 8.5]',
                'the request fields the selected representation depends on: Accept [primary: RFC 9110 12.5.5]',
            ],
            'no source' => [
                [],
                'the language of the representation, which the API chooses without reading the request [primary: RFC'
                . ' 9110 8.5]',
                'the request fields the selected representation depends on: Accept [primary: RFC 9110 12.5.5]',
            ],
        ];
    }

    private static function petstore(): ApiMetadata
    {
        return self::api(self::resources());
    }

    /**
     * `pets` enables every write, `customers` declares no capability and is read one at a time,
     * `orders` is read and never written
     *
     * @param array<value-of<OperationKind>, string> $monitorTypes
     *
     * @return list<ResourceMetadata>
     */
    private static function resources(array $monitorTypes = []): array
    {
        return [
            Samples::resource(
                'pets',
                [Samples::attribute('name', null, true, ['@eq'])],
                [
                    Samples::toOne('owner', 'customers', true, false, [RelationshipUpdate::Replace]),
                    Samples::toMany(
                        'orders',
                        'orders',
                        true,
                        false,
                        [RelationshipUpdate::Add, RelationshipUpdate::Remove],
                    ),
                ],
                [
                    Capability::CollectionReader,
                    Capability::ResourceCreator,
                    Capability::ResourceUpdater,
                    Capability::ResourceDeleter,
                    Capability::RelatedLoader,
                    Capability::RelationshipReplacer,
                    Capability::RelationshipAdder,
                    Capability::RelationshipRemover,
                ],
                monitorTypes: $monitorTypes,
            ),
            Samples::resource('customers', [Samples::attribute('name')]),
            Samples::resource('orders', [Samples::attribute('address')], [], [Capability::CollectionReader]),
        ];
    }

    /**
     * the petstore with the atomic route and an endpoint,
     * whose language comes from `$languageSources`
     */
    private static function languagePetstore(LanguageSources $languageSources): ApiMetadata
    {
        return self::api(self::resources(), Samples::implementation(), [Samples::endpoint()], $languageSources);
    }

    /**
     * @param list<ResourceMetadata> $resources
     * @param list<EndpointMetadata> $endpoints
     */
    private static function api(
        array $resources,
        ?ImplementationReference $transactionBoundary = null,
        array $endpoints = [],
        ?LanguageSources $languageSources = null,
    ): ApiMetadata {
        $registry = new ResourceRegistry($resources);

        return new ApiMetadata(
            'petstore',
            '1.0',
            'acme/v1',
            $registry,
            $endpoints,
            Samples::atomicRoutes($registry, $transactionBoundary),
            Samples::operators(),
            [],
            $transactionBoundary,
            $languageSources ?? Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );
    }
}
