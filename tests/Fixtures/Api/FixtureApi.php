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

namespace Polunich\WpJsonApi\Tests\Fixtures\Api;

use LogicException;
use Polunich\WpJsonApi\Access\AllowAny;
use Polunich\WpJsonApi\Access\EndpointPermission;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Api;
use Polunich\WpJsonApi\ApiBuilder;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\BooleanCodec;
use Polunich\WpJsonApi\Codec\DateTimeCodec;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\NullableCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Declaration\Attribute;
use Polunich\WpJsonApi\Declaration\BodyShape;
use Polunich\WpJsonApi\Declaration\Implementation;
use Polunich\WpJsonApi\Declaration\LanguageSource;
use Polunich\WpJsonApi\Declaration\ResourceType;
use Polunich\WpJsonApi\Declaration\ResponseShape;
use Polunich\WpJsonApi\Declaration\ToMany;
use Polunich\WpJsonApi\Declaration\ToOne;
use Polunich\WpJsonApi\Execution\ContainerImplementationResolver;
use Polunich\WpJsonApi\JsonApi;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\WordPress\RestUrlGenerator;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * an API declared with the public builders,
 * whose types, endpoints and atomic routes between them exercise what the library publishes.
 *
 * the collection of `orders` and the related route of the `owner` of a pet are excluded
 * and respond with 403; the route of one customer and the related route of the `customer`
 * of an order are excluded too, and an endpoint responds in the place of each
 *
 * @internal
 */
final class FixtureApi
{
    public const NAME = 'the fixture api';

    public const VERSION = '1.0.0';

    public const REST_NAMESPACE = 'fixture/v1';

    /** the REST root of a site with pretty permalinks */
    public const REST_ROOT = 'https://example.com/wp-json';

    private const READS = [
        OperationKind::FetchCollection,
        OperationKind::FetchResource,
        OperationKind::FetchRelated,
        OperationKind::FetchRelationship,
    ];

    private function __construct() {}

    /**
     * @param InMemoryStore                          $store           the store behind every port
     *                                                                and the transaction boundary
     * @param OperationPermission&EndpointPermission $writePermission the permission of every write
     *                                                                but the payment webhook
     */
    public static function define(
        InMemoryStore $store,
        OperationPermission&EndpointPermission $writePermission,
    ): ApiBuilder {
        $storeImplementation = Implementation::instance($store);
        $anyone = Implementation::instance(new AllowAny());
        $writePermissionImplementation = Implementation::instance($writePermission);

        $pets = ResourceType::make(InMemoryStore::PETS)
            ->description('pets, each owned by one customer and ordered by many')
            ->id(new DigitsCodec(), static function (Record $record): string {
                return $record->id;
            })
            ->filterId('@eq', '@in')
            ->attribute(
                Attribute::make('name', new StringCodec(1, 200))
                    ->accessor(static function (Record $record): mixed {
                        return $record->attribute('name');
                    })
                    ->creatable()
                    ->updatable()
                    ->required()
                    ->sortable()
                    ->filter('@eq', '@containsi', '@startsWith')
                    ->description('the name of the pet'),
            )
            ->attribute(
                Attribute::make('description', new NullableCodec(new StringCodec()))
                    ->accessor(static function (Record $record): mixed {
                        return $record->attribute('description');
                    })
                    ->creatable()
                    ->updatable(),
            )
            ->attribute(
                Attribute::make('listedAt', new DateTimeCodec())
                    ->accessor(static function (Record $record): mixed {
                        return $record->attribute('listedAt');
                    })
                    ->creatable()
                    ->updatable()
                    ->required()
                    ->sortable()
                    ->filter('@gte', '@lt', '@between'),
            )
            ->relationship(
                ToOne::make('owner', InMemoryStore::CUSTOMERS)
                    ->relatedId(static function (Record $record): ?string {
                        return $record->toOne('owner');
                    })
                    ->notNullable()
                    ->creatable()
                    ->required()
                    ->updatable()
                    ->replaceable()
                    ->except(OperationKind::FetchRelated),
            )
            ->relationship(
                ToMany::make('orders', InMemoryStore::ORDERS)
                    ->linkageAlwaysPresent()
                    ->creatable()
                    ->updatable()
                    ->replaceable()
                    ->addable()
                    ->removable()
                    ->defaultSort('placedAt')
                    ->pagination(5, 20)
                    ->queryParameter(OperationKind::FetchRelated, 'placedAfter', new DateTimeCodec()),
            )
            ->reader($storeImplementation)
            ->loader($storeImplementation)
            ->relatedLoader($storeImplementation)
            ->creator($storeImplementation)
            ->updater($storeImplementation)
            ->deleter($storeImplementation)
            ->relationshipReplacer($storeImplementation)
            ->relationshipAdder($storeImplementation)
            ->relationshipRemover($storeImplementation)
            ->version(static function (Record $record): string {
                return $record->version;
            })
            ->defaultSort('-listedAt')
            ->maxIncludeDepth(2)
            ->queryParameter(OperationKind::FetchCollection, 'namePrefix', new StringCodec(1, 200))
            ->queryParameter(OperationKind::CreateResource, 'notifyOwner', new BooleanCodec())
            ->securitySchemes(OperationKind::CreateResource, SecurityScheme::restNonce());

        $customers = ResourceType::make(InMemoryStore::CUSTOMERS)
            ->id(new DigitsCodec(), static function (Record $record): string {
                return $record->id;
            })
            ->filterId('@eq', '@in')
            ->attribute(
                Attribute::make('name', new StringCodec(1, 100))
                    ->accessor(static function (Record $record): mixed {
                        return $record->attribute('name');
                    })
                    ->sortable()
                    ->filter('@eq', '@containsi'),
            )
            ->except(OperationKind::FetchResource)
            ->reader($storeImplementation)
            ->loader($storeImplementation)
            ->permission(OperationKind::FetchCollection, $anyone);

        $orders = ResourceType::make(InMemoryStore::ORDERS)
            ->id(new UuidCodec(), static function (Record $record): string {
                return $record->id;
            })
            ->idParameter('order_id')
            ->path('/shop/orders')
            ->except(OperationKind::FetchCollection)
            ->clientIds()
            ->attribute(
                Attribute::make('address', new StringCodec(1, 1000))
                    ->accessor(static function (Record $record): mixed {
                        return $record->attribute('address');
                    })
                    ->creatable()
                    ->required(),
            )
            ->attribute(
                Attribute::make('placedAt', new DateTimeCodec())
                    ->accessor(static function (Record $record): mixed {
                        return $record->attribute('placedAt');
                    })
                    ->sortable(),
            )
            ->relationship(
                ToOne::make('customer', InMemoryStore::CUSTOMERS)
                    ->relatedId(static function (Record $record): ?string {
                        return $record->toOne('customer');
                    })
                    ->notNullable()
                    ->creatable()
                    ->required()
                    ->except(OperationKind::FetchRelated),
            )
            ->reader($storeImplementation)
            ->loader($storeImplementation)
            ->creator($storeImplementation)
            ->deleter($storeImplementation);

        foreach (self::READS as $kind) {
            $pets->permission($kind, $anyone);
        }

        // the reader of orders serves the orders of a pet alone
        foreach ([OperationKind::FetchResource, OperationKind::FetchRelationship] as $kind) {
            $orders->permission($kind, $anyone);
        }

        foreach ([
            OperationKind::CreateResource,
            OperationKind::UpdateResource,
            OperationKind::DeleteResource,
            OperationKind::ReplaceRelationship,
            OperationKind::AddToRelationship,
            OperationKind::RemoveFromRelationship,
        ] as $kind) {
            $pets->permission($kind, $writePermissionImplementation);
        }

        $orders->permission(OperationKind::CreateResource, $writePermissionImplementation);
        $orders->permission(OperationKind::DeleteResource, $writePermissionImplementation);

        $api = JsonApi::api(self::NAME, self::VERSION, self::REST_NAMESPACE)
            ->resource($pets)
            ->resource($customers)
            ->resource($orders)
            ->transactionBoundary($storeImplementation)
            ->languageSources(LanguageSource::queryParameter('uiLocale'), LanguageSource::acceptLanguage());

        $api->atomic('/operations')
            ->name('atomicOperations');

        $api->atomic('/pets/operations')
            ->name('petOperations')
            ->types(InMemoryStore::PETS)
            ->description('the operations of one atomic request on pets alone, performed in order and all or'
                . ' nothing');

        $api->get('/customers/{id}', Implementation::instance(FixtureEndpoints::showCustomer()))
            ->name('showCustomer')
            ->bind('id', InMemoryStore::CUSTOMERS)
            ->responds(ResponseShape::record('id'))
            ->permission($anyone)
            ->description('one customer, in place of the route of one customer');

        $api->get('/customers/{id}/pets', Implementation::instance(FixtureEndpoints::customerPets($store)))
            ->name('customerPets')
            ->bind('id', InMemoryStore::CUSTOMERS)
            ->responds(ResponseShape::page(InMemoryStore::PETS))
            ->defaultSort('-name')
            ->pagination(1, 10)
            ->permission($anyone)
            ->description('the pets a customer owns, a page at a time, as the request filters and sorts them');

        $api->get('/shop/orders/{order_id}/customer', Implementation::instance(FixtureEndpoints::orderCustomer($store)))
            ->name('orderCustomer')
            ->bind('order_id', InMemoryStore::ORDERS)
            ->responds(ResponseShape::related('order_id', 'customer'))
            ->permission($anyone)
            ->description('the customer who placed the order, in place of the related route of the customer');

        $api->post('/pets/{id}/adopt', Implementation::instance(FixtureEndpoints::adoptPet($store)))
            ->name('adoptPet')
            ->bind('id', InMemoryStore::PETS)
            ->body(BodyShape::toOne(InMemoryStore::CUSTOMERS))
            ->responds(ResponseShape::record('id'))
            ->permission($writePermissionImplementation)
            ->description('the customer the body names adopts the pet');

        $api->get('/pets/recent', Implementation::instance(FixtureEndpoints::recentPets($store)))
            ->name('recentPets')
            ->queryParameter('listedAfter', new DateTimeCodec(), required: true)
            ->responds(ResponseShape::collection(InMemoryStore::PETS))
            ->permission($anyone)
            ->tag(InMemoryStore::PETS)
            ->description('the pets listed at or after a point in time');

        $api->get('/pets/export', Implementation::instance(FixtureEndpoints::exportPets($store)))
            ->name('exportPets')
            ->responds(ResponseShape::content('text/csv'))
            ->responds(ResponseShape::collection(InMemoryStore::PETS))
            ->permission($anyone)
            ->tag(InMemoryStore::PETS)
            ->description('every pet, as CSV for a spreadsheet or as a JSON:API collection');

        $api->get('/pets/{id}/photo', Implementation::instance(FixtureEndpoints::petPhoto()))
            ->name('petPhoto')
            ->bind('id', InMemoryStore::PETS)
            ->responds(ResponseShape::content('image/png'))
            ->permission($anyone)
            ->description('the photo of the pet');

        $api->post('/pets/{id}/photos', Implementation::instance(FixtureEndpoints::addPetPhoto()))
            ->name('addPetPhoto')
            ->bind('id', InMemoryStore::PETS)
            ->body(BodyShape::form('multipart/form-data')->field(
                'caption',
                new StringCodec(),
                required: true,
            )->file('photo', required: true))
            ->responds(ResponseShape::noContent())
            ->permission($writePermissionImplementation)
            ->leavesUnchanged()
            ->description('a photo of the pet with its caption');

        $api->post('/webhooks/payments', Implementation::instance(FixtureEndpoints::paymentWebhook()))
            ->name('paymentWebhook')
            ->body(BodyShape::json(
                'application/json',
                new ObjectCodec(['id' => new StringCodec(), 'amount' => new IntegerCodec()], ['id', 'amount']),
            ))
            ->responds(ResponseShape::json(
                'application/json',
                new ObjectCodec(['received' => new StringCodec()], ['received']),
            ))
            ->permission($anyone)
            ->securitySchemes(new SecurityScheme('paymentSignature', [
                'type' => 'apiKey',
                'in' => 'header',
                'name' => 'X-Payment-Signature',
                'description' => 'the signature the payment service computes over the body',
            ]))
            ->tag('payments')
            ->description('a payment service tells of a payment in its own JSON');

        $api->get('/stats', Implementation::instance(FixtureEndpoints::stats($store)))
            ->name('stats')
            ->responds(ResponseShape::meta(new ObjectCodec(
                ['pets' => new IntegerCodec(), 'customers' => new IntegerCodec()],
                ['pets', 'customers'],
            )))
            ->permission($anyone)
            ->tag('stats')
            ->description('how many pets and customers the store holds');

        return $api;
    }

    /**
     * the declaration the contract command builds `tests/Fixtures/openapi.json` from,
     * as `extra.wp-jsonapi.api` of `composer.json` names it
     */
    public static function declaration(): ApiBuilder
    {
        return self::define(InMemoryStore::seeded(), new AllowAny());
    }

    /**
     * the bytes `tests/Fixtures/openapi.json` must hold
     */
    public static function contract(): string
    {
        return self::declaration()->build()->openApiJson();
    }

    /**
     * composed where WordPress is not loaded,
     * so links come from a `rest_url()` stand-in that prefixes `REST_ROOT`.
     * the metadata is read from a built `Api`,
     * so requests run through the declaration the contract is built from
     *
     * @param list<Challenge> $challenges
     */
    public static function server(
        InMemoryStore $store,
        OperationPermission&EndpointPermission $writePermission,
        LoggerInterface $logger,
        bool $authenticated = true,
        array $challenges = [],
    ): Server {
        $metadata = (new ReflectionProperty(
            Api::class,
            'metadata',
        ))->getValue(self::define($store, $writePermission)->build());

        if (!$metadata instanceof ApiMetadata) {
            throw new LogicException('an API carries its metadata');
        }

        return Server::compose(
            $metadata,
            new ContainerImplementationResolver(),
            new RestUrlGenerator(self::REST_NAMESPACE, static function (string $path): string {
                return self::REST_ROOT . $path;
            }),
            PetsApi::identity($authenticated),
            PetsApi::challengeProvider($challenges),
            null,
            null,
            [],
            false,
            $logger,
        );
    }
}
