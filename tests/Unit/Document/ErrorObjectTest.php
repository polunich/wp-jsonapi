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
use Polunich\WpJsonApi\Document\ErrorObject;
use Polunich\WpJsonApi\Document\ErrorSource;
use Polunich\WpJsonApi\Document\Links;

/**
 * @internal
 */
#[CoversClass(ErrorObject::class)]
final class ErrorObjectTest extends TestCase
{
    public function testStatusCodeAndTitleAreRequired(): void
    {
        $error = new ErrorObject('422', 'invalid_attribute', 'the attribute is invalid');

        self::assertSame('422', $error->status);
        self::assertSame('invalid_attribute', $error->code);
        self::assertSame('the attribute is invalid', $error->title);
        self::assertNull($error->detail);
        self::assertNull($error->source);
        self::assertNull($error->links);
        self::assertNull($error->meta);
    }

    public function testTheOccurrenceIsDescribedByDetailAndSource(): void
    {
        $source = ErrorSource::pointer('/data/attributes/title');
        $links = new Links(['type' => 'http://example.com/errors/invalid-attribute']);

        $error = new ErrorObject(
            '422',
            'invalid_attribute',
            'the attribute is invalid',
            'the title must not be empty',
            $source,
            $links,
            ['field' => 'title'],
        );

        self::assertSame('the title must not be empty', $error->detail);
        self::assertSame($source, $error->source);
        self::assertSame($links, $error->links);
        self::assertSame(['field' => 'title'], $error->meta);
    }
}
