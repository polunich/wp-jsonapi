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

namespace Polunich\WpJsonApi\Tests\Unit\Document;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Document\DataDocument;
use Polunich\WpJsonApi\Document\Included;
use Polunich\WpJsonApi\Document\JsonApiObject;
use Polunich\WpJsonApi\Document\Links;
use Polunich\WpJsonApi\Document\ResourceIdentifierObject;
use Polunich\WpJsonApi\Document\ResourceObject;

/**
 * @internal
 */
#[CoversClass(DataDocument::class)]
final class DataDocumentTest extends TestCase
{
    public function testPrimaryDataOfNullIsAResult(): void
    {
        $document = new DataDocument(null, new JsonApiObject(), self::links('http://example.com/pets/1/owner'));

        self::assertNull($document->data);
        self::assertNull($document->included);
        self::assertNull($document->meta);
    }

    public function testADocumentWithoutATopLevelSelfLinkIsAccepted(): void
    {
        $links = new Links([]);
        $document = new DataDocument(self::resource('pets', '1'), new JsonApiObject(), $links);

        self::assertSame($links, $document->links);
    }

    /**
     * reachable from a legal request whose include path leads back to the primary resource
     */
    public function testAResourceOfThePrimaryDataIsDroppedFromIncluded(): void
    {
        $pet = self::resource('pets', '1');
        $littermate = self::resource('pets', '2');

        $document = new DataDocument(
            $pet,
            new JsonApiObject(),
            self::links('http://example.com/pets/1'),
            new Included([$pet, $littermate]),
        );

        self::assertNotNull($document->included);
        self::assertSame([$littermate], $document->included->all());
    }

    public function testEveryResourceOfAPrimaryDataListIsDroppedFromIncluded(): void
    {
        $first = self::resource('customers', '1');
        $second = self::resource('customers', '2');
        $order = self::resource('orders', '5');

        $document = new DataDocument(
            [$first, $second],
            new JsonApiObject(),
            self::links('http://example.com/customers'),
            new Included([$order, $second, $first]),
        );

        self::assertNotNull($document->included);
        self::assertSame([$order], $document->included->all());
    }

    public function testTheIdentityIsTheTypeAndTheIdTogether(): void
    {
        $customer = self::resource('customers', '1');
        $order = self::resource('orders', '1');

        $document = new DataDocument(
            $customer,
            new JsonApiObject(),
            self::links('http://example.com/customers/1'),
            new Included([$order]),
        );

        self::assertNotNull($document->included);
        self::assertSame([$order], $document->included->all());
    }

    public function testPrimaryDataOfIdentifiersRemovesNothingFromIncluded(): void
    {
        $order = self::resource('orders', '5');

        $document = new DataDocument(
            [new ResourceIdentifierObject('orders', '5')],
            new JsonApiObject(),
            self::links('http://example.com/pets/1/relationships/orders'),
            new Included([$order]),
        );

        self::assertNotNull($document->included);
        self::assertSame([$order], $document->included->all());
    }

    public function testTheSameTypeAndIdTwiceInThePrimaryDataIsRejected(): void
    {
        $customer = self::resource('customers', '1');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a compound document carries one resource object per type and id; the primary'
            . ' data carries type "customers" and id "1" twice');

        new DataDocument(
            [$customer, self::resource('customers', '1')],
            new JsonApiObject(),
            self::links('http://example.com/customers'),
        );
    }

    public function testAnEmptyIncludedStaysAnInstance(): void
    {
        $document = new DataDocument(
            null,
            new JsonApiObject(),
            self::links('http://example.com/pets/1'),
            new Included(),
        );

        self::assertNotNull($document->included);
        self::assertSame([], $document->included->all());
    }

    public function testAnIncludedThatBecomesEmptyStaysAnInstance(): void
    {
        $customer = self::resource('customers', '1');

        $document = new DataDocument(
            $customer,
            new JsonApiObject(),
            self::links('http://example.com/customers/1'),
            new Included([$customer]),
        );

        self::assertNotNull($document->included);
        self::assertSame([], $document->included->all());
    }

    public function testWithoutIncludeTheDocumentCarriesNoIncluded(): void
    {
        $document = new DataDocument(
            self::resource('customers', '1'),
            new JsonApiObject(),
            self::links('http://example.com/customers/1'),
        );

        self::assertNull($document->included);
    }

    public function testMetaCarriesNonStandardInformation(): void
    {
        $document = new DataDocument(
            [],
            new JsonApiObject(),
            self::links('http://example.com/customers'),
            null,
            ['page' => ['total' => 42]],
        );

        self::assertSame(['page' => ['total' => 42]], $document->meta);
    }

    private static function links(string $self): Links
    {
        return new Links(['self' => $self]);
    }

    private static function resource(string $type, string $id): ResourceObject
    {
        return new ResourceObject($type, $id, new Links(['self' => sprintf('http://example.com/%s/%s', $type, $id)]));
    }
}
