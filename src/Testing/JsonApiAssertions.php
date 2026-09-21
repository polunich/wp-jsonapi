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

namespace Polunich\WpJsonApi\Testing;

use InvalidArgumentException;
use PHPUnit\Framework\Assert;

/**
 * PHPUnit assertions against a committed contract:
 *
 *     final class PetsTest extends WP_UnitTestCase
 *     {
 *         use JsonApiAssertions;
 *
 *         public function testTheCollection(): void
 *         {
 *             // ... the response of GET /pets
 *             self::assertResponseMatchesContract(
 *                 __DIR__ . '/../openapi.json', '/pets', 'GET', 200, $contentType, $body,
 *             );
 *         }
 *     }
 *
 * only `Assert::assertSame()` and `Assert::fail()` are called,
 * which PHPUnit 9.6 and 12.5 both declare,
 * so a project on the PHPUnit 9 of the WordPress test library can use it too.
 * the assertion is static and calls `Assert` by name,
 * so the trait works in any class, a `TestCase` or not
 */
trait JsonApiAssertions
{
    /**
     * a matching response still passes through `assertSame()`, so PHPUnit counts the assertion
     *
     * @param string  $openApiFile the contract the build wrote
     * @param string  $path        relative to the REST namespace, such as `/pets/1`;
     *                             a query string is ignored
     * @param string  $method      in any case, such as `GET`
     * @param ?string $contentType the `Content-Type` of the response, null where it carries none;
     *                             it picks the media type the body is checked against
     * @param string  $body        raw, as sent; empty when there is none
     */
    public static function assertResponseMatchesContract(
        string $openApiFile,
        string $path,
        string $method,
        int $status,
        ?string $contentType,
        string $body,
    ): void {
        try {
            $violations = ContractSchemas::fromFile($openApiFile)->violations(
                $path,
                $method,
                $status,
                $contentType,
                $body,
            );
        } catch (InvalidArgumentException $refusal) {
            Assert::fail($refusal->getMessage());
        }

        if ($violations !== []) {
            Assert::fail(sprintf(
                "the %d response to %s %s does not match the contract %s:\n%s",
                $status,
                ContractSchemas::printable(strtoupper($method)),
                ContractSchemas::printable($path),
                ContractSchemas::printable($openApiFile),
                implode("\n", array_map(static function (string $violation): string {
                    return '- ' . $violation;
                }, $violations)),
            ));
        }

        Assert::assertSame([], $violations);
    }
}
