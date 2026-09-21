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
use Polunich\WpJsonApi\Document\ErrorDocument;
use Polunich\WpJsonApi\Document\ErrorObject;
use Polunich\WpJsonApi\Document\JsonApiObject;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(ErrorDocument::class)]
final class ErrorDocumentTest extends TestCase
{
    public function testTheErrorsKeepTheirOrder(): void
    {
        $first = new ErrorObject('422', 'invalid_attribute', 'the attribute is invalid');
        $second = new ErrorObject('403', 'read_only_field', 'the field is read only');

        $document = new ErrorDocument([$first, $second], new JsonApiObject());

        self::assertSame([$first, $second], $document->errors);
        self::assertSame('1.1', $document->jsonApi->version);
        self::assertNull($document->meta);
    }

    public function testMetaCarriesNonStandardInformationAboutTheFailure(): void
    {
        $document = new ErrorDocument(
            [new ErrorObject('400', 'invalid_query_parameter', 'the query parameter is invalid')],
            new JsonApiObject(),
            ['page' => ['maxSize' => 100]],
        );

        self::assertSame(['page' => ['maxSize' => 100]], $document->meta);
    }

    public function testAnErrorDocumentHasNoLinksAndNoData(): void
    {
        $document = new ReflectionClass(ErrorDocument::class);

        self::assertFalse($document->hasProperty('links'));
        self::assertFalse($document->hasProperty('data'));
    }
}
