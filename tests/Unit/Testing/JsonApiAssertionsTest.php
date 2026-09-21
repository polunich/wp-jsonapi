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

namespace Polunich\WpJsonApi\Tests\Unit\Testing;

use Override;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Testing\JsonApiAssertions;
use Polunich\WpJsonApi\Tests\Fixtures\TemporaryDirectory;
use ReflectionClass;

/**
 * @internal
 */
#[CoversTrait(JsonApiAssertions::class)]
final class JsonApiAssertionsTest extends TestCase
{
    use JsonApiAssertions;

    private string $directory;

    private string $contract;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = TemporaryDirectory::create();
        $this->contract = $this->directory . '/openapi.json';
        file_put_contents($this->contract, json_encode([
            'openapi' => '3.1.2',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => [
                '/pets/{id}' => [
                    'get' => [
                        'responses' => [
                            '200' => ['description' => 'x', 'content' => ['application/vnd.api+json' => ['schema' => [
                                'type' => 'object',
                                'required' => ['data'],
                                'properties' => ['data' => [
                                    'type' => 'object',
                                    'required' => ['type'],
                                    'properties' => ['type' => ['const' => 'pets']],
                                ]],
                            ]]]],
                        ],
                    ],
                    'delete' => ['responses' => ['204' => ['description' => 'gone']]],
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    #[Override]
    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->directory);
    }

    public function testTheTraitIsPublic(): void
    {
        $trait = new ReflectionClass(JsonApiAssertions::class);

        self::assertTrue($trait->isTrait());
        self::assertStringNotContainsString('@internal', (string) $trait->getDocComment());
    }

    public function testAMatchingResponsePassesAsOneAssertion(): void
    {
        $count = Assert::getCount();

        self::assertResponseMatchesContract(
            $this->contract,
            '/pets/1',
            'GET',
            200,
            'application/vnd.api+json',
            '{"data": {"type": "pets"}}',
        );
        self::assertResponseMatchesContract($this->contract, '/pets/1', 'DELETE', 204, null, '');

        self::assertSame($count + 2, Assert::getCount());
    }

    public function testAResponseThatDoesNotMatchFailsWithItsViolations(): void
    {
        try {
            self::assertResponseMatchesContract(
                $this->contract,
                '/pets/1',
                'get',
                200,
                'application/vnd.api+json',
                '{"data": {"type": "customers"}}',
            );
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString(
                sprintf("the 200 response to GET /pets/1 does not match the contract %s:\n- ", $this->contract),
                $failure->getMessage(),
            );
            self::assertStringContainsString('/data/type: The data must match the const value', $failure->getMessage());

            return;
        }

        self::fail('the assertion fails');
    }

    /**
     * a response the contract does not describe is one it does not allow, so it fails, not errors
     */
    public function testAResponseTheContractDoesNotDescribeFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(sprintf(
            'the contract "%s" describes no 418 response to GET /pets/{id}',
            $this->contract,
        ));

        self::assertResponseMatchesContract($this->contract, '/pets/1', 'GET', 418, 'application/vnd.api+json', '{}');
    }

    public function testAFileThatIsNoContractFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(sprintf('the contract "%s/missing.json" cannot be read', $this->directory));

        self::assertResponseMatchesContract(
            $this->directory . '/missing.json',
            '/pets/1',
            'GET',
            200,
            'application/vnd.api+json',
            '{}',
        );
    }

    public function testWorksOutsideATestCase(): void
    {
        $helper = new class {
            use JsonApiAssertions;
        };

        $helper::assertResponseMatchesContract(
            $this->contract,
            '/pets/7',
            'GET',
            200,
            'application/vnd.api+json',
            '{"data": {"type": "pets"}}',
        );

        $this->expectException(AssertionFailedError::class);

        $helper::assertResponseMatchesContract(
            $this->contract,
            '/pets/7',
            'GET',
            200,
            'application/vnd.api+json',
            '{}',
        );
    }
}
