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
use Polunich\WpJsonApi\Document\JsonApiObject;

/**
 * @internal
 */
#[CoversClass(JsonApiObject::class)]
final class JsonApiObjectTest extends TestCase
{
    public function testTheImplementedVersionIsTheDefault(): void
    {
        self::assertSame('1.1', JsonApiObject::VERSION);
        self::assertSame('1.1', (new JsonApiObject())->version);
    }

    public function testWithoutExtensionsAndProfilesTheListsAreEmpty(): void
    {
        $jsonApi = new JsonApiObject();

        self::assertSame([], $jsonApi->ext);
        self::assertSame([], $jsonApi->profile);
    }

    public function testTheAppliedExtensionsAndProfilesAreKept(): void
    {
        $jsonApi = new JsonApiObject(
            ext: ['https://jsonapi.org/ext/atomic'],
            profile: ['https://jsonapi.org/profiles/ethanresnick/cursor-pagination'],
        );

        self::assertSame(['https://jsonapi.org/ext/atomic'], $jsonApi->ext);
        self::assertSame(['https://jsonapi.org/profiles/ethanresnick/cursor-pagination'], $jsonApi->profile);
    }
}
