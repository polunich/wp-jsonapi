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

namespace Polunich\WpJsonApi\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Tests\Fixtures\Api\FixtureApi;
use Polunich\WpJsonApi\Tests\Fixtures\OpenApi\OfficialSchema;

/**
 * the build runs every module a declaration reaches, so no single class is covered
 *
 * @internal
 */
#[CoversNothing]
final class GeneratedOpenApiTest extends TestCase
{
    public const CONTRACT = __DIR__ . '/../Fixtures/openapi.json';

    public function testTheCommittedContractIsTheBuildOfTheDeclarations(): void
    {
        self::assertSame(
            FixtureApi::contract(),
            (string) file_get_contents(self::CONTRACT),
            'tests/Fixtures/openapi.json differs from the build of the fixture API; run bin/wp-jsonapi openapi build',
        );
    }

    public function testTheContractValidatesAgainstTheOfficialSchema(): void
    {
        $errors = OfficialSchema::errors((string) file_get_contents(self::CONTRACT));

        self::assertNull($errors, (string) $errors);
    }

    public function testTwoBuildsAreOneString(): void
    {
        self::assertSame(FixtureApi::contract(), FixtureApi::contract());
    }
}
