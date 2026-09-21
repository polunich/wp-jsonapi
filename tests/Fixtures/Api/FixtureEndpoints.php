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

use DateTimeImmutable;
use Generator;
use LogicException;
use Override;
use Polunich\WpJsonApi\Endpoint\FormBody;
use Polunich\WpJsonApi\Endpoint\Handler;
use Polunich\WpJsonApi\Endpoint\JsonBody;
use Polunich\WpJsonApi\Endpoint\Request;
use Polunich\WpJsonApi\Endpoint\Response;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Filter\AllOf;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\Operators;
use Polunich\WpJsonApi\Store\CollectionQuery;

/**
 * @internal
 */
final class FixtureEndpoints
{
    private function __construct() {}

    /**
     * `GET /customers/{id}`, in place of the excluded route of one customer:
     * the customer the library loaded
     */
    public static function showCustomer(): Handler
    {
        return new readonly class implements Handler {
            #[Override]
            public function handle(Request $request): Response
            {
                return Response::resource($request->record('id'));
            }
        };
    }

    /**
     * `GET /shop/orders/{order_id}/customer`, in place of the excluded related route:
     * the customer who placed the order
     */
    public static function orderCustomer(InMemoryStore $store): Handler
    {
        return new readonly class ($store) implements Handler {
            public function __construct(private InMemoryStore $store) {}

            #[Override]
            public function handle(Request $request): Response
            {
                $order = $request->record('order_id');
                $customer = $order instanceof Record ? $order->toOne('customer') : null;

                return Response::resource($customer === null ? null : $this->store->find(
                    InMemoryStore::CUSTOMERS,
                    $customer,
                ));
            }
        };
    }

    /**
     * `POST /pets/{id}/adopt`: the customer the body names becomes the owner of the pet.
     * the store checks the `If-Match` of the request inside the write
     */
    public static function adoptPet(InMemoryStore $store): Handler
    {
        return new readonly class ($store) implements Handler {
            public function __construct(private InMemoryStore $store) {}

            #[Override]
            public function handle(Request $request): Response
            {
                $owner = $request->body;

                if (!$owner instanceof Linkage || $owner->identifier() === null) {
                    throw new LogicException('the body of an adoption names the customer who adopts');
                }

                $this->store->replace(new ReplaceRelationship(
                    new Identifier(InMemoryStore::PETS, $request->path['id']),
                    'owner',
                    $owner,
                    $request->precondition,
                    null,
                    new SparseFieldsets([]),
                    $request->languages,
                    new Values([]),
                ));

                return Response::resource($this->store->find(InMemoryStore::PETS, $request->path['id']));
            }
        };
    }

    /**
     * `GET /pets/recent`: the pets listed at or after `listedAfter`
     */
    public static function recentPets(InMemoryStore $store): Handler
    {
        return new readonly class ($store) implements Handler {
            public function __construct(private InMemoryStore $store) {}

            #[Override]
            public function handle(Request $request): Response
            {
                $after = $request->queryParameters->get('listedAfter');

                if (!$after instanceof DateTimeImmutable) {
                    throw new LogicException('listedAfter is read as a point in time');
                }

                return Response::collection(array_filter(
                    $this->store->all(InMemoryStore::PETS),
                    static function (Record $pet) use ($after): bool {
                        return $pet->attribute('listedAt') >= $after;
                    },
                ));
            }
        };
    }

    /**
     * `GET /customers/{id}/pets`: the pets the customer owns, a page at a time.
     * the store reads the query the library parsed, narrowed to the owner inside the storage query,
     * so the page is never short of its size
     */
    public static function customerPets(InMemoryStore $store): Handler
    {
        return new readonly class ($store) implements Handler {
            public function __construct(private InMemoryStore $store) {}

            #[Override]
            public function handle(Request $request): Response
            {
                $query = $request->collectionQuery ?? throw new LogicException('a page response carries its query');
                $owned = new Condition(FieldPath::fromString('owner.id'), Operators::EQUAL, $request->path['id']);

                return Response::page($this->store->read(new CollectionQuery(
                    $query->type,
                    $query->filter === null ? $owned : new AllOf([$query->filter, $owned]),
                    $query->sort,
                    $query->window,
                    $query->totalRequested,
                    $query->fields,
                    $query->languages,
                    null,
                    $query->queryParameters,
                )));
            }
        };
    }

    /**
     * `GET /pets/export`: every pet as CSV, one row at a time, or as a JSON:API collection
     */
    public static function exportPets(InMemoryStore $store): Handler
    {
        return new readonly class ($store) implements Handler {
            public function __construct(private InMemoryStore $store) {}

            #[Override]
            public function handle(Request $request): Response
            {
                $pets = $this->store->all(InMemoryStore::PETS);

                if ($request->mediaType !== 'text/csv') {
                    return Response::collection($pets);
                }

                return Response::content(Content::chunks((static function () use ($pets): Generator {
                    yield "id,name\n";

                    foreach ($pets as $pet) {
                        $name = $pet->attribute('name');

                        yield sprintf("%s,%s\n", $pet->id, is_string($name) ? $name : '');
                    }
                })()), 'text/csv', 'pets.csv');
            }
        };
    }

    /**
     * `GET /pets/{id}/photo`: the photo of the pet, a file the library reads piece by piece
     */
    public static function petPhoto(): Handler
    {
        return new readonly class implements Handler {
            #[Override]
            public function handle(Request $request): Response
            {
                return Response::content(Content::file(__DIR__ . '/rex.png'), 'image/png');
            }
        };
    }

    /**
     * `POST /pets/{id}/photos`: a photo of the pet with its caption, from a multipart form;
     * the pet stays as it is
     */
    public static function addPetPhoto(): Handler
    {
        return new readonly class implements Handler {
            #[Override]
            public function handle(Request $request): Response
            {
                $form = $request->body;

                if (!$form instanceof FormBody || $form->file('photo')->error !== UPLOAD_ERR_OK) {
                    throw new LogicException('the form of a photo carries the photo');
                }

                return Response::noContent();
            }
        };
    }

    /**
     * `POST /webhooks/payments`: a payment service tells of a payment in its own JSON;
     * the response confirms it
     */
    public static function paymentWebhook(): Handler
    {
        return new readonly class implements Handler {
            #[Override]
            public function handle(Request $request): Response
            {
                $payment = $request->body;

                if (!$payment instanceof JsonBody || !is_array($payment->value)) {
                    throw new LogicException('a payment arrives as a JSON object');
                }

                return Response::json(['received' => $payment->value['id'] ?? null], 'application/json');
            }
        };
    }

    /**
     * `GET /stats`: how many pets and customers the store holds
     */
    public static function stats(InMemoryStore $store): Handler
    {
        return new readonly class ($store) implements Handler {
            public function __construct(private InMemoryStore $store) {}

            #[Override]
            public function handle(Request $request): Response
            {
                return Response::meta([
                    'pets' => count($this->store->all(InMemoryStore::PETS)),
                    'customers' => count($this->store->all(InMemoryStore::CUSTOMERS)),
                ]);
            }
        };
    }
}
