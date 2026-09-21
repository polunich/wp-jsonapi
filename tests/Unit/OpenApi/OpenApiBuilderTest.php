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
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\OpenApi\OpenApiBuilder;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\OpenApi\OfficialSchema;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(OpenApiBuilder::class)]
final class OpenApiBuilderTest extends TestCase
{
    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(OpenApiBuilder::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testWritesTheMembersOfTheOpenApiObjectInTheirOrder(): void
    {
        $document = (new OpenApiBuilder(self::petstore()))->document();

        self::assertSame(
            ['openapi', 'info', 'servers', 'paths', 'components', 'security', 'tags'],
            array_keys(get_object_vars($document)),
        );
        self::assertSame('3.1.2', $document->openapi);
        self::assertEquals((object) ['title' => 'petstore', 'version' => '1.0'], $document->info);
    }

    public function testTheServerNamesTheNamespaceAndTheTwoValuesOfTheSite(): void
    {
        $servers = (new OpenApiBuilder(self::petstore()))->document()->servers;

        self::assertIsArray($servers);
        self::assertCount(1, $servers);

        $server = $servers[0];

        self::assertInstanceOf(stdClass::class, $server);
        self::assertSame(['url', 'description', 'variables'], array_keys(get_object_vars($server)));
        self::assertSame('{site}/{restPrefix}/acme/v1', $server->url);

        $variables = $server->variables;

        self::assertInstanceOf(stdClass::class, $variables);
        self::assertSame(['site', 'restPrefix'], array_keys(get_object_vars($variables)));
        self::assertSame('http://localhost', self::object($variables, 'site')->default);
        self::assertSame('wp-json', self::object($variables, 'restPrefix')->default);
    }

    public function testEverySegmentOfTheNamespaceIsPercentEncoded(): void
    {
        $api = new ApiMetadata(
            'petstore',
            '1.0',
            'acme plugin/v1',
            new ResourceRegistry([]),
            [],
            [],
            Samples::operators(),
            [],
            null,
            Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );
        $servers = (new OpenApiBuilder($api))->document()->servers;

        self::assertIsArray($servers);
        self::assertInstanceOf(stdClass::class, $servers[0]);
        self::assertSame('{site}/{restPrefix}/acme%20plugin/v1', $servers[0]->url);
    }

    public function testWritesTheComponentsOfTheThreeBuildersInOneObject(): void
    {
        $components = self::object((new OpenApiBuilder(self::petstore()))->document(), 'components');

        self::assertSame(
            ['schemas', 'parameters', 'headers', 'securitySchemes'],
            array_keys(get_object_vars($components)),
        );

        $schemas = array_keys(get_object_vars(self::object($components, 'schemas')));

        self::assertSame('jsonapi', $schemas[0]);
        self::assertContains('pets.collection', $schemas);
        self::assertContains('pets.filter', $schemas);
        self::assertContains('atomicOperations.operations', $schemas);
        self::assertSame($schemas, array_values(array_unique($schemas)));
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
            array_keys(get_object_vars(self::object($components, 'headers'))),
        );
        self::assertEquals(
            (object) ['applicationPasswords' => (object) ['type' => 'http', 'scheme' => 'basic']],
            self::object($components, 'securitySchemes'),
        );
    }

    public function testAnApiThatNegotiatesNoLanguageWritesNoParametersComponent(): void
    {
        $api = new ApiMetadata(
            'petstore',
            '1.0',
            'acme/v1',
            new ResourceRegistry([]),
            [],
            [],
            Samples::operators(),
            [],
            null,
            new LanguageSources([]),
            NamingPolicy::SnakeCase,
            true,
        );
        $components = self::object((new OpenApiBuilder($api))->document(), 'components');

        self::assertSame(['schemas', 'headers', 'securitySchemes'], array_keys(get_object_vars($components)));
    }

    public function testTheSecurityOfTheDocumentIsTheOneOfTheDeclaration(): void
    {
        $security = (new OpenApiBuilder(self::petstore()))->document()->security;

        self::assertEquals([(object) ['applicationPasswords' => []], new stdClass()], $security);
    }

    public function testTheTagsAreLeftOutWhereThereIsNone(): void
    {
        $api = new ApiMetadata(
            'petstore',
            '1.0',
            'acme/v1',
            new ResourceRegistry([]),
            [],
            [],
            Samples::operators(),
            [],
            null,
            Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );
        $document = (new OpenApiBuilder($api))->document();

        self::assertObjectNotHasProperty('tags', $document);
        self::assertEquals(new stdClass(), $document->paths);
    }

    public function testTheJsonIsTheDocumentAsTheEncoderWritesIt(): void
    {
        $json = (new OpenApiBuilder(self::petstore()))->json();

        self::assertStringStartsWith("{\n    \"openapi\": \"3.1.2\",\n", $json);
        self::assertStringEndsWith("\n}\n", $json);
        self::assertSame($json, (new OpenApiBuilder(self::petstore()))->json());
        self::assertSame(
            $json,
            json_encode(
                json_decode($json, false, 512, JSON_THROW_ON_ERROR),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n",
        );
    }

    public function testTheContractValidatesAgainstTheOfficialSchema(): void
    {
        $errors = OfficialSchema::errors((new OpenApiBuilder(self::petstore()))->json());

        self::assertNull($errors, (string) $errors);
    }

    /**
     * guards the validation above against a schema setup that accepts anything
     */
    public function testTheOfficialSchemaRefusesADocumentWithoutAVersion(): void
    {
        $document = json_decode((new OpenApiBuilder(self::petstore()))->json(), false, 512, JSON_THROW_ON_ERROR);

        self::assertInstanceOf(stdClass::class, $document);
        self::assertInstanceOf(stdClass::class, $document->info);
        unset($document->info->version);

        self::assertNotNull(OfficialSchema::errors($document));
    }

    /**
     * the transaction boundary runs the operations of `/operations`
     */
    private static function petstore(): ApiMetadata
    {
        $pets = Samples::resource(
            'pets',
            [Samples::attribute('name', null, true, ['@eq'])],
            [
                Samples::toOne('owner', 'customers', true, false, [RelationshipUpdate::Replace]),
                Samples::toMany('orders', 'orders', true, false, [RelationshipUpdate::Add, RelationshipUpdate::Remove]),
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
        );

        $registry = new ResourceRegistry([$pets, self::plain('customers'), self::plain('orders')]);
        $boundary = ImplementationReference::service('transactions');

        return new ApiMetadata(
            'petstore',
            '1.0',
            'acme/v1',
            $registry,
            [],
            Samples::atomicRoutes($registry, $boundary),
            Samples::operators(),
            [SecurityScheme::applicationPasswords()],
            $boundary,
            Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );
    }

    private static function plain(string $type): ResourceMetadata
    {
        return Samples::resource($type, [Samples::attribute('name')], [], [Capability::CollectionReader]);
    }

    private static function object(stdClass $holder, string $name): stdClass
    {
        $members = get_object_vars($holder);

        self::assertArrayHasKey($name, $members);
        self::assertInstanceOf(stdClass::class, $members[$name]);

        return $members[$name];
    }
}
