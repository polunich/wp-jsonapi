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

namespace Polunich\WpJsonApi\Tests\Fixtures\Endpoint;

use Polunich\WpJsonApi\Access\AllowAny;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Declaration\Attribute;
use Polunich\WpJsonApi\Declaration\Endpoint;
use Polunich\WpJsonApi\Declaration\Implementation;
use Polunich\WpJsonApi\Declaration\ResourceType;
use Polunich\WpJsonApi\Declaration\ResponseShape;
use Polunich\WpJsonApi\Declaration\ToMany;
use Polunich\WpJsonApi\Declaration\ToOne;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;

/**
 * a pet store and endpoints declared over it:
 * versioned pets with a name to sort and filter by, a to-one owner and to-many orders,
 * customers with a name, orders with UUID ids
 *
 * @internal
 */
final class EndpointSamples
{
    private function __construct() {}

    public static function registry(): ResourceRegistry
    {
        $store = Implementation::service('store');
        $anyone = Implementation::instance(new AllowAny());
        $id = static function (Pet $pet): string {
            return $pet->id;
        };
        $name = Attribute::make('name', new StringCodec(1, 50))
            ->accessor(static function (Pet $pet): string {
                return $pet->name;
            })
            ->creatable()
            ->updatable()
            ->required()
            ->sortable()
            ->filter('@eq');

        return new ResourceRegistry([
            ResourceType::make('pets')
                ->id(new DigitsCodec(), $id)
                ->attribute($name)
                ->relationship(ToOne::make('owner', 'customers')->relatedId(static function (Pet $pet): ?string {
                    return $pet->ownerId;
                })->creatable()->updatable())
                ->relationship(ToMany::make('orders', 'orders'))
                ->loader($store)
                ->relatedLoader($store)
                ->permission(OperationKind::FetchResource, $anyone)
                ->permission(OperationKind::FetchRelated, $anyone)
                ->permission(OperationKind::FetchRelationship, $anyone)
                ->version(static function (Pet $pet): string {
                    return $pet->version;
                })
                ->build(),
            ResourceType::make('customers')->id(new DigitsCodec(), $id)->attribute(
                Attribute::make('name', new StringCodec())->accessor(static function (Pet $pet): string {
                    return $pet->name;
                }),
            )->loader($store)->permission(OperationKind::FetchResource, $anyone)->build(),
            ResourceType::make('orders')->id(new UuidCodec(), $id)->reader($store)->loader($store)
                ->permission(OperationKind::FetchCollection, $anyone)
                ->permission(OperationKind::FetchResource, $anyone)
                ->build(),
        ]);
    }

    /**
     * @param callable(Endpoint): mixed $declare adds the rest of the declaration
     */
    public static function endpoint(string $method, string $path, callable $declare): EndpointMetadata
    {
        $endpoint = (new Endpoint($method, $path, Implementation::service('probe handler')))
            ->name('probe')
            ->permission(Implementation::instance(new AllowAny()));
        $declare($endpoint);

        return $endpoint->build(self::registry());
    }

    public static function search(): EndpointMetadata
    {
        return self::endpoint('GET', '/pets/search', static function (Endpoint $endpoint): void {
            $endpoint->responds(ResponseShape::page('pets'))->pagination(2, 5)->defaultSort('-name');
        });
    }

    public static function card(): EndpointMetadata
    {
        return self::endpoint('GET', '/pets/{id}/card', static function (Endpoint $endpoint): void {
            $endpoint->bind('id', 'pets')->responds(ResponseShape::record('id'));
        });
    }
}
