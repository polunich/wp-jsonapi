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
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AttributeMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\IdMetadata;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\WriteRule;
use Polunich\WpJsonApi\OpenApi\DocumentSchemaBuilder;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(DocumentSchemaBuilder::class)]
final class DocumentSchemaBuilderTest extends TestCase
{
    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(DocumentSchemaBuilder::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testEveryTypeAndRelationshipCarriesItsSchemasInDeclarationOrder(): void
    {
        $builder = new DocumentSchemaBuilder(self::petstore());

        self::assertSame(
            [
                'jsonapi',
                'link',
                'nullableLink',
                'error',
                'errors',
                'pets.resource',
                'pets.identifier',
                'pets.document',
                'pets.collection',
                'pets.create',
                'pets.update',
                'pets.owner.linkage',
                'pets.owner.relationship',
                'pets.owner.related',
                'pets.owner.update',
                'pets.orders.linkage',
                'pets.orders.relationship',
                'pets.orders.related',
                'pets.orders.update',
                'customers.resource',
                'customers.identifier',
                'customers.document',
                'customers.collection',
                'customers.create',
                'customers.update',
                'orders.resource',
                'orders.identifier',
                'orders.document',
                'orders.collection',
                'orders.create',
                'orders.update',
                'orders.groomer.linkage',
                'orders.groomer.relationship',
                'orders.groomer.related',
                'orders.groomer.update',
                'groomers.resource',
                'groomers.identifier',
                'groomers.document',
                'groomers.collection',
                'groomers.create',
                'groomers.update',
            ],
            array_keys($builder->schemas()),
        );
    }

    #[DataProvider('names')]
    public function testAComponentNameEscapesWhatAComponentKeyMayNotHold(string $type, string $expected): void
    {
        $name = DocumentSchemaBuilder::documentName($type);

        self::assertSame($expected, $name);
        self::assertSame(1, preg_match('/^[a-zA-Z0-9\.\-_]+$/D', $name));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'letters' => ['pets', 'pets.document'];
        yield 'a name of digits' => ['0', '0.document'];
        yield 'an underscore and a hyphen' => ['pet_toys-2', 'pet_toys-2.document'];
        yield 'a space' => ['pet toys', '.70657420746F7973.document'];
        yield 'a character above U+007F' => ["f\u{e9}lins", '.66C3A96C696E73.document'];
    }

    public function testEveryReferenceNamesASchemaOfTheContract(): void
    {
        $schemas = (new DocumentSchemaBuilder(self::petstore()))->schemas();

        foreach (self::references($schemas) as $reference) {
            self::assertStringStartsWith('#/components/schemas/', $reference);
            self::assertArrayHasKey(substr($reference, strlen('#/components/schemas/')), $schemas);
        }
    }

    /**
     * `draft` has no accessor, so no response carries it
     */
    public function testTheResourceObjectCarriesTheReadableFieldsAndItsSelfLink(): void
    {
        $schemas = (new DocumentSchemaBuilder(self::petstore()))->schemas();
        $resource = self::schema($schemas, 'pets.resource');

        self::assertSame(
            ['type', 'id', 'attributes', 'relationships', 'links'],
            self::members($resource, 'properties'),
        );
        self::assertSame(['type', 'id', 'links'], self::at($resource, 'required'));
        self::assertFalse(self::at($resource, 'additionalProperties'));
        self::assertSame('pets', self::at($resource, 'properties', 'type', 'const'));
        self::assertSame('string', self::at($resource, 'properties', 'id', 'type'));
        self::assertSame(['name', 'views'], self::members($resource, 'properties', 'attributes', 'properties'));
        self::assertSame(
            'the name of the pet',
            self::at($resource, 'properties', 'attributes', 'properties', 'name', 'description'),
        );
        self::assertSame(['owner', 'orders'], self::members($resource, 'properties', 'relationships', 'properties'));
        self::assertSame(['self'], self::at($resource, 'properties', 'links', 'required'));
    }

    public function testARelationshipObjectRequiresItsLinkageOnlyWhereItIsAlwaysLinked(): void
    {
        $resource = self::schema((new DocumentSchemaBuilder(self::petstore()))->schemas(), 'pets.resource');
        $owner = ['properties', 'relationships', 'properties', 'owner'];
        $orders = ['properties', 'relationships', 'properties', 'orders'];

        self::assertSame(['links', 'data'], self::at($resource, ...$owner, ...['required']));
        self::assertSame(['links'], self::at($resource, ...$orders, ...['required']));
        self::assertSame(
            ['self', 'related'],
            self::members($resource, ...$owner, ...['properties', 'links', 'properties']),
        );
        self::assertSame(
            ['self', 'related', 'first', 'last', 'prev', 'next'],
            self::members($resource, ...$orders, ...['properties', 'links', 'properties']),
        );
    }

    public function testTheLinkageFollowsTheCardinalityAndTheNullabilityOfTheRelationship(): void
    {
        $schemas = (new DocumentSchemaBuilder(self::petstore()))->schemas();

        self::assertSame(
            '#/components/schemas/customers.identifier',
            self::at(self::schema($schemas, 'pets.owner.linkage'), '$ref'),
        );
        self::assertSame('array', self::at(self::schema($schemas, 'pets.orders.linkage'), 'type'));
        self::assertSame(
            '#/components/schemas/orders.identifier',
            self::at(self::schema($schemas, 'pets.orders.linkage'), 'items', '$ref'),
        );
        self::assertSame(
            ['#/components/schemas/groomers.identifier', 'null'],
            [
                self::at(self::schema($schemas, 'orders.groomer.linkage'), 'anyOf', 0, '$ref'),
                self::at(self::schema($schemas, 'orders.groomer.linkage'), 'anyOf', 1, 'type'),
            ],
        );
    }

    public function testTheCreateDocumentAppliesTheWriteRulesOfTheFields(): void
    {
        $create = self::schema((new DocumentSchemaBuilder(self::petstore()))->schemas(), 'pets.create');
        $data = ['properties', 'data'];

        self::assertSame(['data'], self::at($create, 'required'));
        self::assertSame(
            ['type', 'lid', 'attributes', 'relationships', 'links', 'meta'],
            self::members($create, ...$data, ...['properties']),
        );
        self::assertSame(['type', 'attributes', 'relationships'], self::at($create, ...$data, ...['required']));
        self::assertSame(
            ['name', 'draft'],
            self::members($create, ...$data, ...['properties', 'attributes', 'properties']),
        );
        self::assertSame(['name'], self::at($create, ...$data, ...['properties', 'attributes', 'required']));
        self::assertSame(['owner'], self::members($create, ...$data, ...['properties', 'relationships', 'properties']));
        self::assertSame(
            ['data'],
            self::at($create, ...$data, ...['properties', 'relationships', 'properties', 'owner', 'required']),
        );
    }

    #[DataProvider('clientIdPolicies')]
    public function testACreateCarriesTheIdWhereTheResourceAcceptsAClientId(
        ClientIdPolicy $policy,
        bool $described,
        bool $required,
    ): void {
        $api = self::api([Samples::resource(
            'pets',
            [Samples::attribute('name')],
            clientIdPolicy: $policy,
            id: new IdMetadata(new DigitsCodec(), static function (object $record): string {
                return 'an id';
            }, true),
        )]);
        $create = self::schema((new DocumentSchemaBuilder($api))->schemas(), 'pets.create');

        self::assertSame($described, in_array('id', self::members($create, 'properties', 'data', 'properties'), true));
        self::assertSame($required, in_array('id', (array) self::at($create, 'properties', 'data', 'required'), true));
    }

    /**
     * @return iterable<string, array{ClientIdPolicy, bool, bool}>
     */
    public static function clientIdPolicies(): iterable
    {
        yield 'forbidden' => [ClientIdPolicy::Forbidden, false, false];
        yield 'allowed' => [ClientIdPolicy::Allowed, true, false];
        yield 'required' => [ClientIdPolicy::Required, true, true];
    }

    public function testTheUpdateDocumentRequiresTypeAndIdAndCarriesTheUpdatableFields(): void
    {
        $update = self::schema((new DocumentSchemaBuilder(self::petstore()))->schemas(), 'pets.update');

        self::assertSame(
            ['type', 'id', 'attributes', 'relationships', 'links', 'meta'],
            self::members($update, 'properties', 'data', 'properties'),
        );
        self::assertSame(['type', 'id'], self::at($update, 'properties', 'data', 'required'));
        self::assertSame(
            ['name', 'draft'],
            self::members($update, 'properties', 'data', 'properties', 'attributes', 'properties'),
        );
        self::assertSame(
            'string',
            self::at($update, 'properties', 'data', 'properties', 'attributes', 'properties', 'name', 'type'),
        );
        self::assertSame(
            'the name of the pet',
            self::at($update, 'properties', 'data', 'properties', 'attributes', 'properties', 'name', 'description'),
        );
    }

    public function testTheRelationshipDocumentsCarryTheLinkageAsPrimaryData(): void
    {
        $schemas = (new DocumentSchemaBuilder(self::petstore()))->schemas();
        $request = self::schema($schemas, 'pets.orders.update');
        $document = self::schema($schemas, 'pets.orders.relationship');

        self::assertSame(['data', 'meta', 'jsonapi', 'links', 'included'], self::members($request, 'properties'));
        self::assertSame('#/components/schemas/pets.orders.linkage', self::at($request, 'properties', 'data', '$ref'));
        self::assertSame(['jsonapi', 'links', 'data', 'included', 'meta'], self::members($document, 'properties'));
        self::assertSame('#/components/schemas/pets.orders.linkage', self::at($document, 'properties', 'data', '$ref'));
        self::assertSame(
            ['self', 'related', 'first', 'last', 'prev', 'next'],
            self::members($document, 'properties', 'links', 'properties'),
        );
        self::assertSame(['self', 'related'], self::at($document, 'properties', 'links', 'required'));
    }

    /**
     * the object refuses `links` as it refuses every member the library never writes
     */
    public function testAResourceOfATypeThatExcludesTheFetchOfItsResourcesDescribesNoLinks(): void
    {
        $resource = self::schema((new DocumentSchemaBuilder(self::excludingPetstore()))->schemas(), 'pets.resource');

        self::assertSame(['type', 'id', 'attributes', 'relationships'], self::members($resource, 'properties'));
        self::assertSame(['type', 'id'], self::at($resource, 'required'));
    }

    /**
     * @param list<string> $links
     */
    #[DataProvider('keptRoutes')]
    public function testARelationshipObjectDescribesTheLinksOfTheRoutesItKeeps(string $name, array $links): void
    {
        $resource = self::schema((new DocumentSchemaBuilder(self::excludingPetstore()))->schemas(), 'pets.resource');
        $relationship = ['properties', 'relationships', 'properties', $name];

        self::assertSame(['links', 'data'], self::at($resource, ...$relationship, ...['required']));
        self::assertSame($links, self::members($resource, ...$relationship, ...['properties', 'links', 'properties']));
        self::assertSame($links, self::at($resource, ...$relationship, ...['properties', 'links', 'required']));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function keptRoutes(): iterable
    {
        yield 'the relationship route alone' => ['owner', ['self']];
        yield 'the related route alone' => ['vet', ['related']];
    }

    /**
     * a relationship with neither route carries its linkage in every document, and no link
     */
    public function testARelationshipObjectWithoutBothRoutesDescribesItsLinkageAlone(): void
    {
        $resource = self::schema((new DocumentSchemaBuilder(self::excludingPetstore()))->schemas(), 'pets.resource');
        $breeder = ['properties', 'relationships', 'properties', 'breeder'];

        self::assertSame(['data'], self::at($resource, ...$breeder, ...['required']));
        self::assertSame(['data'], self::members($resource, ...$breeder, ...['properties']));
    }

    /**
     * the create and the update respond with this document and link the route of the resource,
     * so the document requires the self link exactly where that route responds
     */
    public function testAResourceDocumentRequiresItsSelfLinkWhereTheFetchOfTheResourceResponds(): void
    {
        $schemas = (new DocumentSchemaBuilder(self::excludingPetstore()))->schemas();

        self::assertSame(
            ['jsonapi', 'data', 'included'],
            self::members(self::schema($schemas, 'pets.document'), 'properties'),
        );
        self::assertSame(['jsonapi', 'data'], self::at(self::schema($schemas, 'pets.document'), 'required'));
        self::assertSame(
            ['jsonapi', 'links', 'data'],
            self::at(self::schema($schemas, 'customers.document'), 'required'),
        );
    }

    /**
     * @param list<string> $links
     */
    #[DataProvider('keptRoutesOfADocument')]
    public function testARelationshipDocumentDescribesTheLinksOfTheRoutesItKeeps(string $name, array $links): void
    {
        $document = self::schema(
            (new DocumentSchemaBuilder(self::excludingPetstore()))->schemas(),
            'pets.' . $name . '.relationship',
        );

        self::assertSame($links, self::members($document, 'properties', 'links', 'properties'));
        self::assertSame($links, self::at($document, 'properties', 'links', 'required'));
        self::assertSame(['jsonapi', 'links', 'data'], self::at($document, 'required'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function keptRoutesOfADocument(): iterable
    {
        yield 'the relationship route alone' => ['owner', ['self']];
        yield 'the related route alone' => ['vet', ['related']];
    }

    public function testARelationshipDocumentWithoutBothRoutesDescribesNoLinks(): void
    {
        $document = self::schema(
            (new DocumentSchemaBuilder(self::excludingPetstore()))->schemas(),
            'pets.breeder.relationship',
        );

        self::assertSame(['jsonapi', 'data', 'included'], self::members($document, 'properties'));
        self::assertSame(['jsonapi', 'data'], self::at($document, 'required'));
    }

    public function testTheRelatedDocumentsCarryTheRelatedResourcesAsPrimaryData(): void
    {
        $schemas = (new DocumentSchemaBuilder(self::petstore()))->schemas();

        self::assertSame(
            '#/components/schemas/orders.resource',
            self::at(self::schema($schemas, 'pets.orders.related'), 'properties', 'data', 'items', '$ref'),
        );
        self::assertSame(
            '#/components/schemas/customers.resource',
            self::at(self::schema($schemas, 'pets.owner.related'), 'properties', 'data', '$ref'),
        );
        self::assertSame(
            '#/components/schemas/groomers.resource',
            self::at(self::schema($schemas, 'orders.groomer.related'), 'properties', 'data', 'anyOf', 0, '$ref'),
        );
    }

    public function testIncludedNamesTheTypesTheIncludePathsReach(): void
    {
        $shallow = (new DocumentSchemaBuilder(self::petstore()))->schemas();
        $deep = (new DocumentSchemaBuilder(self::petstore(2)))->schemas();

        self::assertSame(
            ['#/components/schemas/customers.resource', '#/components/schemas/orders.resource'],
            self::references([self::at(self::schema($shallow, 'pets.collection'), 'properties', 'included')]),
        );
        self::assertSame(
            [
                '#/components/schemas/customers.resource',
                '#/components/schemas/orders.resource',
                '#/components/schemas/groomers.resource',
            ],
            self::references([self::at(self::schema($deep, 'pets.document'), 'properties', 'included')]),
        );
        self::assertSame(
            0,
            self::at(self::schema($shallow, 'groomers.document'), 'properties', 'included', 'maxItems'),
        );
    }

    public function testARelationshipDocumentCountsTheMaxIncludeDepthFromTheTypeThatDeclaresIt(): void
    {
        $shallow = (new DocumentSchemaBuilder(self::petstore()))->schemas();
        $deep = (new DocumentSchemaBuilder(self::petstore(2)))->schemas();

        self::assertSame(
            ['#/components/schemas/orders.resource'],
            self::references([self::at(self::schema($shallow, 'pets.orders.relationship'), 'properties', 'included')]),
        );
        self::assertSame(
            ['#/components/schemas/orders.resource', '#/components/schemas/groomers.resource'],
            self::references([self::at(self::schema($deep, 'pets.orders.relationship'), 'properties', 'included')]),
        );
    }

    public function testACollectionDocumentCarriesThePaginationLinksAndTheTotals(): void
    {
        $collection = self::schema((new DocumentSchemaBuilder(self::petstore()))->schemas(), 'pets.collection');

        self::assertSame(['jsonapi', 'links', 'data', 'included', 'meta'], self::members($collection, 'properties'));
        self::assertSame(['jsonapi', 'links', 'data'], self::at($collection, 'required'));
        self::assertSame(
            ['self', 'first', 'last', 'prev', 'next'],
            self::members($collection, 'properties', 'links', 'properties'),
        );
        self::assertSame('array', self::at($collection, 'properties', 'data', 'type'));
        self::assertSame(
            ['total', 'estimated_total', 'range_truncated'],
            self::members($collection, 'properties', 'meta', 'properties', 'page', 'properties'),
        );
        self::assertSame(
            ['best_guess'],
            self::members(
                $collection,
                'properties',
                'meta',
                'properties',
                'page',
                'properties',
                'estimated_total',
                'properties',
            ),
        );
    }

    public function testTheCamelCasePolicySpellsTheTotalsAsTheProfileDoes(): void
    {
        $api = self::api(self::resources(), naming: NamingPolicy::CamelCase);
        $collection = self::schema((new DocumentSchemaBuilder($api))->schemas(), 'pets.collection');

        self::assertSame(
            ['total', 'estimatedTotal', 'rangeTruncated'],
            self::members($collection, 'properties', 'meta', 'properties', 'page', 'properties'),
        );
        self::assertSame(
            ['bestGuess'],
            self::members(
                $collection,
                'properties',
                'meta',
                'properties',
                'page',
                'properties',
                'estimatedTotal',
                'properties',
            ),
        );
    }

    public function testTheErrorDocumentCarriesErrorObjects(): void
    {
        $schemas = (new DocumentSchemaBuilder(self::petstore()))->schemas();
        $document = self::schema($schemas, DocumentSchemaBuilder::errorDocumentName());
        $error = self::schema($schemas, 'error');

        self::assertSame(['jsonapi', 'errors', 'meta'], self::members($document, 'properties'));
        self::assertSame(['jsonapi', 'errors'], self::at($document, 'required'));
        self::assertSame(1, self::at($document, 'properties', 'errors', 'minItems'));
        self::assertSame(
            ['status', 'code', 'title', 'detail', 'source', 'links', 'meta'],
            self::members($error, 'properties'),
        );
        self::assertSame(['status', 'code', 'title'], self::at($error, 'required'));
        self::assertSame('string', self::at($error, 'properties', 'status', 'type'));
        self::assertSame('^[45][0-9]{2}$', self::at($error, 'properties', 'status', 'pattern'));
        self::assertSame(
            ['pointer', 'parameter', 'header'],
            self::members($error, 'properties', 'source', 'properties'),
        );
        self::assertSame(1, self::at($error, 'properties', 'source', 'minProperties'));
        self::assertSame(1, self::at($error, 'properties', 'source', 'maxProperties'));
        self::assertSame(['type'], self::members($error, 'properties', 'links', 'properties'));
    }

    public function testEveryDocumentCarriesTheJsonApiObject(): void
    {
        $schemas = (new DocumentSchemaBuilder(self::petstore()))->schemas();
        $jsonApi = self::schema($schemas, 'jsonapi');

        self::assertSame(['version', 'ext', 'profile'], self::members($jsonApi, 'properties'));
        self::assertSame('1.1', self::at($jsonApi, 'properties', 'version', 'const'));
        self::assertSame(['version'], self::at($jsonApi, 'required'));

        foreach (['pets.document', 'pets.collection', 'pets.orders.relationship', 'pets.owner.related'] as $name) {
            self::assertSame(
                '#/components/schemas/jsonapi',
                self::at(self::schema($schemas, $name), 'properties', 'jsonapi', '$ref'),
            );
        }
    }

    public function testARelationshipThatForbidsInclusionReachesNoTypeInItsDocument(): void
    {
        $api = self::api([
            Samples::resource(
                'pets',
                [Samples::attribute('name')],
                [Samples::toMany('watchers', 'customers', false, false), Samples::toMany('orders', 'customers')],
                [Capability::RelatedLoader],
            ),
            Samples::resource('customers', [Samples::attribute('name')], [], [Capability::CollectionReader]),
        ]);
        $schemas = (new DocumentSchemaBuilder($api))->schemas();
        $forbidden = self::schema($schemas, 'pets.watchers.relationship');

        self::assertSame(0, self::at($forbidden, 'properties', 'included', 'maxItems'));
        self::assertSame([], self::references([self::at($forbidden, 'properties', 'included')]));
        self::assertSame(
            ['#/components/schemas/customers.resource'],
            self::references([self::at(self::schema($schemas, 'pets.orders.relationship'), 'properties', 'included')]),
        );
    }

    public function testARelatedDocumentCountsTheMaxIncludeDepthFromTheRelatedType(): void
    {
        $api = self::api([
            Samples::resource('pets', [Samples::attribute('name')], [Samples::toOne('owner', 'customers')]),
            Samples::resource(
                'customers',
                [Samples::attribute('name')],
                [Samples::toOne('breeder', 'breeders')],
                maxIncludeDepth: 2,
            ),
            Samples::resource('breeders', [Samples::attribute('name')], [Samples::toOne('trainer', 'trainers')]),
            Samples::resource('trainers', [Samples::attribute('name')]),
        ]);
        $schemas = (new DocumentSchemaBuilder($api))->schemas();

        self::assertSame(
            ['#/components/schemas/breeders.resource', '#/components/schemas/trainers.resource'],
            self::references([self::at(self::schema($schemas, 'pets.owner.related'), 'properties', 'included')]),
        );
        self::assertSame(
            ['#/components/schemas/customers.resource'],
            self::references([self::at(self::schema($schemas, 'pets.owner.relationship'), 'properties', 'included')]),
        );
    }

    public function testTheShapesOfAnAtomicOperationAreWrittenWithTheTransactionBoundaryAlone(): void
    {
        $atomic = static function (string $name): bool {
            return str_ends_with($name, '.atomic');
        };
        $without = array_keys((new DocumentSchemaBuilder(self::petstore()))->schemas());
        $with = array_keys((new DocumentSchemaBuilder(self::atomicPetstore()))->schemas());

        self::assertSame([], array_values(array_filter($without, $atomic)));
        self::assertSame(
            [
                'pets.identifier.atomic',
                'pets.create.atomic',
                'pets.update.atomic',
                'pets.owner.linkage.atomic',
                'pets.orders.linkage.atomic',
                'customers.identifier.atomic',
                'customers.create.atomic',
                'customers.update.atomic',
                'orders.identifier.atomic',
                'orders.create.atomic',
                'orders.update.atomic',
                'orders.groomer.linkage.atomic',
                'groomers.identifier.atomic',
                'groomers.create.atomic',
                'groomers.update.atomic',
            ],
            array_values(array_filter($with, $atomic)),
        );
        self::assertSame(
            $without,
            array_values(array_filter($with, static function (string $name): bool {
                return !str_ends_with($name, '.atomic');
            })),
        );
    }

    public function testAnAtomicUpdateNamesItsTargetByItsIdOrByALid(): void
    {
        $schemas = (new DocumentSchemaBuilder(self::atomicPetstore()))->schemas();
        $update = self::schema($schemas, 'pets.update.atomic');

        self::assertSame(
            ['type', 'id', 'lid', 'attributes', 'relationships', 'links', 'meta'],
            self::members($update, 'properties'),
        );
        self::assertSame(['type'], self::at($update, 'required'));
        self::assertFalse(self::at($update, 'additionalProperties'));
        self::assertEquals(
            [(object) ['required' => ['id']], (object) ['required' => ['lid']]],
            self::at($update, 'oneOf'),
        );
        self::assertSame('string', self::at($update, 'properties', 'lid', 'type'));
        self::assertSame(['name', 'draft'], self::members($update, 'properties', 'attributes', 'properties'));

        $single = self::schema($schemas, 'pets.update');

        self::assertSame(
            ['type', 'id', 'attributes', 'relationships', 'links', 'meta'],
            self::members($single, 'properties', 'data', 'properties'),
        );
        self::assertSame(['type', 'id'], self::at($single, 'properties', 'data', 'required'));
    }

    public function testALinkageInsideAnAtomicOperationAdmitsALid(): void
    {
        $schemas = (new DocumentSchemaBuilder(self::atomicPetstore()))->schemas();
        $identifier = self::schema($schemas, 'customers.identifier.atomic');

        self::assertSame(['type', 'id', 'lid', 'meta'], self::members($identifier, 'properties'));
        self::assertSame(['type'], self::at($identifier, 'required'));
        self::assertFalse(self::at($identifier, 'additionalProperties'));
        self::assertEquals(
            [(object) ['required' => ['id']], (object) ['required' => ['lid']]],
            self::at($identifier, 'oneOf'),
        );
        self::assertSame('string', self::at($identifier, 'properties', 'lid', 'type'));
        self::assertSame(['type', 'id'], self::at(self::schema($schemas, 'customers.identifier'), 'required'));
        self::assertSame(
            '#/components/schemas/customers.identifier.atomic',
            self::at(self::schema($schemas, 'pets.owner.linkage.atomic'), '$ref'),
        );
        self::assertSame(
            '#/components/schemas/orders.identifier.atomic',
            self::at(self::schema($schemas, 'pets.orders.linkage.atomic'), 'items', '$ref'),
        );
        self::assertSame(
            '#/components/schemas/groomers.identifier.atomic',
            self::at(self::schema($schemas, 'orders.groomer.linkage.atomic'), 'anyOf', 0, '$ref'),
        );

        $relationships = ['properties', 'relationships', 'properties', 'owner', 'properties', 'data', '$ref'];

        self::assertSame(
            '#/components/schemas/pets.owner.linkage.atomic',
            self::at(self::schema($schemas, 'pets.create.atomic'), ...$relationships),
        );
        self::assertSame(
            '#/components/schemas/pets.owner.linkage.atomic',
            self::at(self::schema($schemas, 'pets.update.atomic'), ...$relationships),
        );
        self::assertSame(
            '#/components/schemas/pets.owner.linkage',
            self::at(self::schema($schemas, 'pets.create'), 'properties', 'data', ...$relationships),
        );
    }

    public function testAnAtomicCreateCarriesTheResourceObjectOfACreate(): void
    {
        $schemas = (new DocumentSchemaBuilder(self::atomicPetstore()))->schemas();
        $create = self::schema($schemas, 'pets.create.atomic');

        self::assertSame(
            ['type', 'lid', 'attributes', 'relationships', 'links', 'meta'],
            self::members($create, 'properties'),
        );
        self::assertSame(['type', 'attributes', 'relationships'], self::at($create, 'required'));
        self::assertSame(['name', 'draft'], self::members($create, 'properties', 'attributes', 'properties'));
        self::assertSame(['name'], self::at($create, 'properties', 'attributes', 'required'));
        self::assertObjectNotHasProperty('oneOf', $create);
    }

    /**
     * the `source` of an error object is skipped:
     * it pins its one member, which an @-member beside it would break
     */
    public function testEveryObjectSchemaAdmitsAnAtMember(): void
    {
        $closed = self::closedObjects((new DocumentSchemaBuilder(self::atomicPetstore()))->schemas());

        self::assertNotSame([], $closed);

        foreach ($closed as $where => $schema) {
            self::assertEquals(
                (object) ['^@' => true],
                self::at($schema, 'patternProperties'),
                sprintf('the object at %s admits an @-member', $where),
            );
        }
    }

    public function testARequestDocumentNamesTheTopLevelMembersTheParserIgnores(): void
    {
        $schemas = (new DocumentSchemaBuilder(self::petstore()))->schemas();

        foreach (['pets.create', 'pets.update', 'pets.orders.update'] as $name) {
            $document = self::schema($schemas, $name);

            self::assertSame(
                ['data', 'meta', 'jsonapi', 'links', 'included'],
                self::members($document, 'properties'),
                $name,
            );
            self::assertSame(['data'], self::at($document, 'required'), $name);
            self::assertSame('object', self::at($document, 'properties', 'meta', 'type'), $name);
            self::assertSame('array', self::at($document, 'properties', 'included', 'type'), $name);
        }
    }

    public function testAnIdAndALidAreNeverTheEmptyString(): void
    {
        $schemas = (new DocumentSchemaBuilder(self::atomicPetstore()))->schemas();

        self::assertSame(1, self::at($schemas['pets.resource'], 'properties', 'id', 'minLength'));
        self::assertSame(1, self::at($schemas['pets.identifier'], 'properties', 'id', 'minLength'));
        self::assertSame(1, self::at($schemas['pets.update'], 'properties', 'data', 'properties', 'id', 'minLength'));
        self::assertSame(1, self::at($schemas['pets.create'], 'properties', 'data', 'properties', 'lid', 'minLength'));
        self::assertSame(1, self::at($schemas['pets.identifier.atomic'], 'properties', 'lid', 'minLength'));
    }

    public function testACreateRefusesAnIdAndALidTogether(): void
    {
        $accepting = self::api([Samples::resource(
            'pets',
            [Samples::attribute('name')],
            clientIdPolicy: ClientIdPolicy::Allowed,
            id: new IdMetadata(new DigitsCodec(), static function (object $record): string {
                return 'an id';
            }, true),
        )]);
        $schemas = (new DocumentSchemaBuilder($accepting))->schemas();

        self::assertEquals(
            (object) ['required' => ['id', 'lid']],
            self::at($schemas['pets.create'], 'properties', 'data', 'not'),
        );

        $forbidding = (new DocumentSchemaBuilder(self::petstore()))->schemas();
        $data = self::at($forbidding['pets.create'], 'properties', 'data');

        self::assertInstanceOf(stdClass::class, $data);
        self::assertObjectNotHasProperty('not', $data);
    }

    /**
     * `tags` declares no update, so it accepts no replacement
     */
    public function testAnUpdateLeavesOutAToManyRelationshipItCannotReplace(): void
    {
        $api = self::api([
            Samples::resource(
                'pets',
                [Samples::attribute('name')],
                [new RelationshipMetadata(
                    'tags',
                    Cardinality::ToMany,
                    'tags',
                    null,
                    false,
                    false,
                    true,
                    [],
                    RouteExclusion::none(RouteOwner::Relationship),
                    new WriteRule(true, true, false),
                    null,
                    null,
                    null,
                    false,
                )],
                [Capability::ResourceCreator, Capability::ResourceUpdater, Capability::RelatedLoader],
            ),
            Samples::resource('tags', [Samples::attribute('label')], [], [Capability::CollectionReader]),
        ]);
        $schemas = (new DocumentSchemaBuilder($api))->schemas();

        self::assertSame(
            ['tags'],
            self::members($schemas['pets.create'], 'properties', 'data', 'properties', 'relationships', 'properties'),
        );
        self::assertNotContains(
            'relationships',
            self::members($schemas['pets.update'], 'properties', 'data', 'properties'),
        );
    }

    /**
     * the self link stands where the endpoint responds to GET:
     * another method's URL does not respond to a GET
     */
    public function testTheRecordAnEndpointRespondsWithIsLinkedWhereItRespondsToGet(): void
    {
        $builder = new DocumentSchemaBuilder(self::petstore());
        $record = new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'id', null, null, null);

        $read = $builder->endpointDocument(Samples::endpoint('petCard', 'GET', '/pets/{id}/card'), $record);
        $write = $builder->endpointDocument(Samples::endpoint(), $record);

        self::assertSame(['jsonapi', 'links', 'data', 'included'], self::members($read, 'properties'));
        self::assertSame(['jsonapi', 'links', 'data'], self::at($read, 'required'));
        self::assertEquals(DocumentSchemaBuilder::reference('pets.resource'), self::at($read, 'properties', 'data'));
        self::assertSame(['jsonapi', 'data', 'included'], self::members($write, 'properties'));
        self::assertSame(['jsonapi', 'data'], self::at($write, 'required'));
    }

    public function testTheCreatedResourceOfAnEndpointIsLinkedWhereItsFetchResponds(): void
    {
        $builder = new DocumentSchemaBuilder(self::petstore());

        $response = new ResponseMetadata(ResponseShapeKind::Created, 'pets', null, null, null, null);

        $created = $builder->endpointDocument(Samples::endpoint(responses: [201 => $response]), $response);

        self::assertSame(['jsonapi', 'links', 'data', 'included'], self::members($created, 'properties'));
        self::assertSame(['self'], self::members($created, 'properties', 'links', 'properties'));
    }

    public function testTheCollectionOfAnEndpointIsAListWithoutPages(): void
    {
        $builder = new DocumentSchemaBuilder(self::petstore());

        $response = new ResponseMetadata(ResponseShapeKind::Collection, 'pets', null, null, null, null);

        $collection = $builder->endpointDocument(
            Samples::endpoint('recentPets', 'GET', '/pets/recent', [], responses: [200 => $response]),
            $response,
        );

        self::assertSame(['self'], self::members($collection, 'properties', 'links', 'properties'));
        self::assertSame('array', self::at($collection, 'properties', 'data', 'type'));
        self::assertEquals(
            DocumentSchemaBuilder::reference('pets.resource'),
            self::at($collection, 'properties', 'data', 'items'),
        );
        self::assertNotContains('meta', self::members($collection, 'properties'));
    }

    /**
     * a page writes the collection document of its type, pagination links and `meta.page` included
     */
    public function testThePageOfAnEndpointIsTheCollectionDocumentOfItsType(): void
    {
        $response = new ResponseMetadata(ResponseShapeKind::Page, 'pets', null, null, null, null);

        self::assertEquals(
            DocumentSchemaBuilder::reference(DocumentSchemaBuilder::collectionName('pets')),
            (new DocumentSchemaBuilder(self::petstore()))->endpointDocument(Samples::pageEndpoint(), $response),
        );
    }

    #[DataProvider('relatedResources')]
    public function testTheRelatedResourceOfAnEndpointIsNullWhereTheRelationshipCanBeEmpty(bool $nullable): void
    {
        $api = self::api([
            Samples::resource(
                'pets',
                [Samples::attribute('name')],
                [Samples::toOne('owner', 'customers', true, $nullable)],
                [Capability::RelatedLoader],
            ),
            Samples::resource('customers', [Samples::attribute('name')]),
        ]);
        $response = new ResponseMetadata(
            ResponseShapeKind::Related,
            'customers',
            'id',
            'owner',
            Cardinality::ToOne,
            null,
        );

        $endpoint = Samples::endpoint('petOwner', 'GET', '/pets/{id}/keeper', responses: [200 => $response]);

        $data = self::at(
            (new DocumentSchemaBuilder($api))->endpointDocument($endpoint, $response),
            'properties',
            'data',
        );

        self::assertEquals(
            $nullable
                ? (object) [
                    'anyOf' => [DocumentSchemaBuilder::reference('customers.resource'), (object) ['type' => 'null']],
                    'description' => 'null where the relationship is empty [spec, Top Level]',
                ]
                : DocumentSchemaBuilder::reference('customers.resource'),
            $data,
        );
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function relatedResources(): iterable
    {
        yield 'a relationship that can be empty' => [true];
        yield 'a relationship that is not nullable' => [false];
    }

    /**
     * @param list<string> $links
     */
    #[DataProvider('linkages')]
    public function testTheLinkageOfAnEndpointLinksItselfWhereItRespondsToGetAndTheRelatedRoute(
        string $method,
        array $links,
    ): void {
        $response = new ResponseMetadata(
            ResponseShapeKind::Linkage,
            'orders',
            'id',
            'orders',
            Cardinality::ToMany,
            null,
        );

        $endpoint = Samples::endpoint('petOrders', $method, '/pets/{id}/order-ids', responses: [200 => $response]);

        $linkage = (new DocumentSchemaBuilder(self::petstore()))->endpointDocument($endpoint, $response);

        self::assertSame($links, self::members($linkage, 'properties', 'links', 'properties'));
        self::assertSame($links, self::at($linkage, 'properties', 'links', 'required'));
        self::assertEquals(
            DocumentSchemaBuilder::reference('pets.orders.linkage'),
            self::at($linkage, 'properties', 'data'),
        );
        self::assertEquals(
            DocumentSchemaBuilder::reference('orders.resource'),
            self::at($linkage, 'properties', 'included', 'items'),
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function linkages(): iterable
    {
        yield 'a response to GET' => ['GET', ['self', 'related']];
        yield 'a response to POST' => ['POST', ['related']];
    }

    /**
     * a read-only member of an attribute's object is described for responses alone, as a field is,
     * and a write-only one for requests alone
     */
    public function testAMemberOfAnAttributeIsDescribedInTheDirectionItTravels(): void
    {
        $schedule = new ObjectCodec(
            ['version' => new StringCodec(), 'secret' => new StringCodec(), 'day' => new StringCodec()],
            ['version', 'secret', 'day'],
            readOnly: ['version'],
            writeOnly: ['secret'],
        );
        $api = self::api([Samples::resource(
            'pets',
            [Samples::attribute('schedule', $schedule, writeRule: new WriteRule(true, true, false))],
            [],
            [Capability::ResourceCreator, Capability::ResourceUpdater],
        )]);
        $schemas = (new DocumentSchemaBuilder($api))->schemas();
        $attribute = ['properties', 'attributes', 'properties', 'schedule'];
        $written = ['properties', 'data', ...$attribute];

        $read = self::at(self::schema($schemas, 'pets.resource'), ...$attribute);
        self::assertSame(['version', 'day'], self::members($read, 'properties'));
        self::assertSame(['version', 'day'], self::at($read, 'required'));
        self::assertTrue(self::at($read, 'properties', 'version', 'readOnly'));

        foreach (['pets.create', 'pets.update'] as $name) {
            $request = self::at(self::schema($schemas, $name), ...$written);
            self::assertSame(['secret', 'day'], self::members($request, 'properties'), $name);
            self::assertSame(['secret', 'day'], self::at($request, 'required'), $name);
            self::assertTrue(self::at($request, 'properties', 'secret', 'writeOnly'), $name);
        }
    }

    public function testTheMetaOfAnEndpointIsDescribedInTheDirectionItTravels(): void
    {
        $codec = new ObjectCodec(
            ['version' => new StringCodec(), 'secret' => new StringCodec()],
            readOnly: ['version'],
            writeOnly: ['secret'],
        );
        $builder = new DocumentSchemaBuilder(self::petstore());
        $response = new ResponseMetadata(ResponseShapeKind::Meta, null, null, null, null, $codec);

        $written = $builder->endpointDocument(Samples::endpoint(responses: [200 => $response]), $response);
        $read = $builder->endpointBody(new BodyMetadata(BodyShapeKind::Meta, null, null, $codec));

        self::assertEquals($codec->schema()->forResponse()->toObject(), self::at($written, 'properties', 'meta'));
        self::assertEquals($codec->schema()->forRequest()->toObject(), self::at($read, 'properties', 'meta'));
        self::assertSame(['version'], self::members($written, 'properties', 'meta', 'properties'));
        self::assertSame(['secret'], self::members($read, 'properties', 'meta', 'properties'));
    }

    public function testTheMetaOfAnEndpointIsADocumentOfTopLevelMetaAlone(): void
    {
        $codec = new ObjectCodec(['pets' => new IntegerCodec()], ['pets']);
        $response = new ResponseMetadata(ResponseShapeKind::Meta, null, null, null, null, $codec);

        $meta = (new DocumentSchemaBuilder(self::petstore()))->endpointDocument(
            Samples::endpoint('stats', 'GET', '/stats', [], responses: [200 => $response]),
            $response,
        );

        self::assertSame(['jsonapi', 'meta'], self::members($meta, 'properties'));
        self::assertSame(['jsonapi', 'meta'], self::at($meta, 'required'));
        self::assertEquals($codec->schema()->toObject(), self::at($meta, 'properties', 'meta'));
    }

    public function testAMonitorIsTheDocumentOfItsTypeAndNoContentIsNone(): void
    {
        $builder = new DocumentSchemaBuilder(self::petstore());
        $accepted = new ResponseMetadata(ResponseShapeKind::Accepted, 'orders', null, null, null, null);
        $noContent = new ResponseMetadata(ResponseShapeKind::NoContent, null, null, null, null, null);
        $endpoint = Samples::endpoint(responses: [202 => $accepted, 204 => $noContent]);

        self::assertEquals(
            DocumentSchemaBuilder::reference(DocumentSchemaBuilder::documentName('orders')),
            $builder->endpointDocument($endpoint, $accepted),
        );
        self::assertNull($builder->endpointDocument($endpoint, $noContent));
    }

    public function testTheResourceABodyCarriesIsReadAsTheCreateOrTheUpdateReadsIt(): void
    {
        $builder = new DocumentSchemaBuilder(self::petstore());

        self::assertEquals(
            DocumentSchemaBuilder::reference(DocumentSchemaBuilder::createRequestName('pets')),
            $builder->endpointBody(new BodyMetadata(BodyShapeKind::Resource, 'pets', null, null)),
        );
        self::assertEquals(
            DocumentSchemaBuilder::reference(DocumentSchemaBuilder::updateRequestName('pets')),
            $builder->endpointBody(new BodyMetadata(BodyShapeKind::Record, 'pets', 'id', null)),
        );
    }

    public function testTheLinkageABodyCarriesIsOneIdentifierOrNullOrAListOfThem(): void
    {
        $builder = new DocumentSchemaBuilder(self::petstore());
        $identifier = DocumentSchemaBuilder::reference('customers.identifier');

        $one = $builder->endpointBody(new BodyMetadata(BodyShapeKind::ToOne, 'customers', null, null));
        $many = $builder->endpointBody(new BodyMetadata(BodyShapeKind::ToMany, 'customers', null, null));

        self::assertSame(['data', 'meta', 'jsonapi', 'links', 'included'], self::members($one, 'properties'));
        self::assertSame(['data'], self::at($one, 'required'));
        self::assertEquals(
            (object) ['anyOf' => [$identifier, (object) ['type' => 'null']]],
            self::at($one, 'properties', 'data'),
        );
        self::assertEquals((object) ['type' => 'array', 'items' => $identifier], self::at($many, 'properties', 'data'));
    }

    public function testTheMetaABodyCarriesIsReadByItsCodec(): void
    {
        $codec = new StringCodec();

        $meta = (new DocumentSchemaBuilder(self::petstore()))->endpointBody(new BodyMetadata(
            BodyShapeKind::Meta,
            null,
            null,
            $codec,
        ));

        self::assertSame(['meta', 'jsonapi', 'links'], self::members($meta, 'properties'));
        self::assertSame(['meta'], self::at($meta, 'required'));
        self::assertEquals($codec->schema()->toObject(), self::at($meta, 'properties', 'meta'));
    }

    /**
     * keyed by where each object stands
     *
     * @param array<string, stdClass> $schemas
     *
     * @return array<string, stdClass>
     */
    private static function closedObjects(array $schemas): array
    {
        $closed = [];

        foreach ($schemas as $name => $schema) {
            foreach (self::walk($schema, $name) as $where => $object) {
                $closed[$where] = $object;
            }
        }

        return $closed;
    }

    /**
     * @return array<string, stdClass>
     */
    private static function walk(mixed $value, string $where): array
    {
        if (is_array($value)) {
            $found = [];

            foreach ($value as $key => $item) {
                $found += self::walk($item, $where . '/' . (string) $key);
            }

            return $found;
        }

        if (!$value instanceof stdClass) {
            return [];
        }

        $closes = property_exists($value, 'additionalProperties') && $value->additionalProperties === false;
        $found = $closes && !property_exists($value, 'maxProperties') ? [$where => $value] : [];

        foreach (get_object_vars($value) as $key => $member) {
            if ($key === 'patternProperties') {
                continue;
            }

            $found += self::walk($member, $where . '/' . (string) $key);
        }

        return $found;
    }

    public function testTheSchemasComeOutTheSameOnEveryBuild(): void
    {
        $first = (new DocumentSchemaBuilder(self::petstore()))->schemas();
        $second = (new DocumentSchemaBuilder(self::petstore()))->schemas();

        self::assertSame(
            json_encode($first, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            json_encode($second, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * `pets` excludes the fetch of its resources; of the relationship reads,
     * `owner` keeps the relationship route, `vet` the related route and `breeder` neither
     */
    private static function excludingPetstore(): ApiMetadata
    {
        $reads = static function (OperationKind $kind, OperationKind ...$kinds): RouteExclusion {
            return RouteExclusion::except(RouteOwner::Relationship, [$kind, ...array_values($kinds)], 'a relationship');
        };

        return self::api([
            Samples::resource(
                'pets',
                [Samples::attribute('name')],
                [
                    Samples::toOne('owner', 'customers', excluded: $reads(OperationKind::FetchRelated)),
                    Samples::toOne('vet', 'customers', excluded: $reads(OperationKind::FetchRelationship)),
                    Samples::toOne(
                        'breeder',
                        'customers',
                        excluded: $reads(OperationKind::FetchRelated, OperationKind::FetchRelationship),
                    ),
                ],
                [Capability::ResourceLoader],
                excluded: RouteExclusion::except(RouteOwner::Resource, [OperationKind::FetchResource], 'type "pets"'),
            ),
            Samples::resource('customers', [Samples::attribute('name')]),
        ]);
    }

    private static function atomicPetstore(): ApiMetadata
    {
        return self::api(self::resources(), ImplementationReference::service('transactions'));
    }

    private static function petstore(int $maxIncludeDepth = 1): ApiMetadata
    {
        return self::api(self::resources($maxIncludeDepth));
    }

    /**
     * @return list<ResourceMetadata>
     */
    private static function resources(int $maxIncludeDepth = 1): array
    {
        $pets = Samples::resource(
            'pets',
            [
                new AttributeMetadata(
                    'name',
                    new StringCodec(),
                    static function (object $record): string {
                        return 'a name';
                    },
                    new WriteRule(true, true, true),
                    true,
                    ['@eq', '@contains'],
                    'the name of the pet',
                    false,
                ),
                new AttributeMetadata(
                    'views',
                    new IntegerCodec(),
                    static function (object $record): int {
                        return 0;
                    },
                    WriteRule::readOnly(),
                    true,
                    ['@between'],
                    null,
                    false,
                ),
                new AttributeMetadata(
                    'draft',
                    new StringCodec(),
                    null,
                    new WriteRule(true, true, false),
                    false,
                    [],
                    null,
                    false,
                ),
            ],
            [
                Samples::toOne('owner', 'customers', true, false, [RelationshipUpdate::Replace]),
                Samples::toMany(
                    'orders',
                    'orders',
                    true,
                    false,
                    [RelationshipUpdate::Add, RelationshipUpdate::Remove],
                    Sort::fromString('-address'),
                    new PaginationPolicy(5, 25),
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
            defaultSort: Sort::fromString('-name'),
            paginationPolicy: new PaginationPolicy(20, 50),
            maxIncludeDepth: $maxIncludeDepth,
        );
        $customers = Samples::resource('customers', [Samples::attribute('name', null, true, ['@eq'])]);
        $orders = Samples::resource(
            'orders',
            [Samples::attribute('address', null, true)],
            [Samples::toOne('groomer', 'groomers')],
            [Capability::CollectionReader],
        );
        $groomers = Samples::resource('groomers', [Samples::attribute('name')]);

        return [$pets, $customers, $orders, $groomers];
    }

    /**
     * @param list<ResourceMetadata> $resources
     */
    private static function api(
        array $resources,
        ?ImplementationReference $transactionBoundary = null,
        NamingPolicy $naming = NamingPolicy::SnakeCase,
    ): ApiMetadata {
        $registry = new ResourceRegistry($resources);

        return new ApiMetadata(
            'petstore',
            '1.0',
            'acme/v1',
            $registry,
            [],
            Samples::atomicRoutes($registry, $transactionBoundary),
            Samples::operators(),
            [],
            $transactionBoundary,
            Samples::languageSources(),
            $naming,
            true,
        );
    }

    /**
     * @param array<string, stdClass> $schemas
     */
    private static function schema(array $schemas, string $name): stdClass
    {
        self::assertArrayHasKey($name, $schemas);

        return $schemas[$name];
    }

    private static function at(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $segment) {
            if (is_int($segment)) {
                self::assertIsArray($value);
                self::assertArrayHasKey($segment, $value);
                $value = $value[$segment];

                continue;
            }

            self::assertInstanceOf(stdClass::class, $value);
            self::assertTrue(
                property_exists($value, $segment),
                sprintf('the schema carries the member "%s"', $segment),
            );
            $value = $value->{$segment};
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function members(mixed $value, int|string ...$path): array
    {
        $object = self::at($value, ...$path);
        self::assertInstanceOf(stdClass::class, $object);

        return array_map(static function (int|string $name): string {
            return (string) $name;
        }, array_keys(get_object_vars($object)));
    }

    /**
     * @return list<string>
     */
    private static function references(mixed $value): array
    {
        $found = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                $found = array_merge($found, self::references($item));
            }

            return $found;
        }

        if (!$value instanceof stdClass) {
            return [];
        }

        foreach (get_object_vars($value) as $name => $member) {
            if ($name === '$ref') {
                self::assertIsString($member);
                $found[] = $member;

                continue;
            }

            $found = array_merge($found, self::references($member));
        }

        return $found;
    }
}
