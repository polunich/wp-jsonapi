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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Document\AtomicResultsDocument;
use Polunich\WpJsonApi\Document\JsonApiObject;
use Polunich\WpJsonApi\Document\Links;
use Polunich\WpJsonApi\Document\ResourceObject;
use ReflectionClass;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(AtomicResultsDocument::class)]
final class AtomicResultsDocumentTest extends TestCase
{
    public function testTheResultsKeepTheOrderOfTheOperations(): void
    {
        $first = self::resource('pets', '1');
        $second = self::resource('pets', '2');

        $document = new AtomicResultsDocument([$first, null, $second], self::jsonApi());

        self::assertSame([$first, null, $second], $document->results);
        self::assertNull($document->meta);
    }

    public function testAResultWithoutMembersIsNull(): void
    {
        self::assertSame([null], (new AtomicResultsDocument([null], self::jsonApi()))->results);
    }

    public function testTheExtensionUriTravelsInTheJsonApiObject(): void
    {
        $document = new AtomicResultsDocument([null], self::jsonApi());

        self::assertSame(['https://jsonapi.org/ext/atomic'], $document->jsonApi->ext);
    }

    public function testMetaCarriesNonStandardInformationAboutTheRequest(): void
    {
        self::assertSame(['took' => 12], (new AtomicResultsDocument([null], self::jsonApi(), ['took' => 12]))->meta);
    }

    public function testAResultIsAResourceObjectOrNullAndNothingElse(): void
    {
        $constructor = (new ReflectionClass(AtomicResultsDocument::class))->getConstructor();

        self::assertNotNull($constructor);

        $names = array_map(
            static function (ReflectionParameter $parameter): string {
                return $parameter->getName();
            },
            $constructor->getParameters(),
        );

        self::assertSame(['results', 'jsonApi', 'meta'], $names);
    }

    public function testTheDocumentHasNoDataAndNoIncluded(): void
    {
        $document = new ReflectionClass(AtomicResultsDocument::class);

        self::assertFalse($document->hasProperty('data'));
        self::assertFalse($document->hasProperty('included'));
    }

    private static function jsonApi(): JsonApiObject
    {
        return new JsonApiObject(ext: ['https://jsonapi.org/ext/atomic']);
    }

    private static function resource(string $type, string $id): ResourceObject
    {
        return new ResourceObject($type, $id, new Links(['self' => sprintf('http://example.com/%s/%s', $type, $id)]));
    }
}
