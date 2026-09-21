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

namespace Polunich\WpJsonApi\Tests\Unit\Declaration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Declaration\LanguageSource;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(LanguageSource::class)]
final class LanguageSourceTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndPublicApi(): void
    {
        $class = new ReflectionClass(LanguageSource::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringNotContainsString('@internal', (string) $class->getDocComment());
        self::assertStringContainsString('@internal', (string) $class->getMethod('build')->getDocComment());
    }

    public function testAQuerySourceCarriesTheNameOfItsParameter(): void
    {
        self::assertSame('uiLocale', LanguageSource::queryParameter('uiLocale')->build()->queryParameter);
    }

    public function testTheFieldSourceNamesNoParameter(): void
    {
        self::assertNull(LanguageSource::acceptLanguage()->build()->queryParameter);
    }

    public function testANameJsonApiOrWordPressKeepsIsRefusedWhereItIsWritten(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the query parameter "sort" is a family JSON:API defines itself');

        LanguageSource::queryParameter('sort');
    }
}
