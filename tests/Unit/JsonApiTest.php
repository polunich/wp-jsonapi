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

namespace Polunich\WpJsonApi\Tests\Unit;

use Closure;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Api;
use Polunich\WpJsonApi\ApiBuilder;
use Polunich\WpJsonApi\Declaration\Implementation;
use Polunich\WpJsonApi\JsonApi;
use Polunich\WpJsonApi\Tests\Fixtures\Api\PetsApi;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\CoreFiles;
use ReflectionClass;
use ReflectionParameter;
use stdClass;

/**
 * @internal
 */
#[CoversClass(JsonApi::class)]
final class JsonApiTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        if (function_exists('add_action')) {
            CoreFiles::resetHooks();
        }
    }

    /**
     * the parameter names of `api()` are public API: a caller may pass them as named arguments
     */
    public function testTheClassIsAPublicFinalFacadeWithNoInstance(): void
    {
        $class = new ReflectionClass(JsonApi::class);

        self::assertTrue($class->isFinal());
        self::assertDoesNotMatchRegularExpression('/@internal\b/', (string) $class->getDocComment());
        self::assertTrue($class->getConstructor()?->isPrivate());
        self::assertSame(['name', 'version', 'restNamespace'], array_map(
            static function (ReflectionParameter $parameter): string {
                return $parameter->getName();
            },
            $class->getMethod('api')->getParameters(),
        ));
    }

    public function testTheBuilderDeclaresTheNameTheVersionAndTheNamespace(): void
    {
        $builder = JsonApi::api('the pets api', '2.1.0', 'acme/v2');

        self::assertInstanceOf(ApiBuilder::class, $builder);

        $contract = json_decode(
            $builder->resource(PetsApi::pets(
                PetsApi::store(),
                Implementation::instance(PetsApi::permission(true)),
            ))->build()->openApiJson(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($contract);
        self::assertSame(['title' => 'the pets api', 'version' => '2.1.0'], $contract['info'] ?? null);
        self::assertIsArray($contract['servers'] ?? null);
        self::assertIsArray($contract['servers'][0] ?? null);
        self::assertIsString($contract['servers'][0]['url'] ?? null);
        self::assertStringEndsWith('/acme/v2', $contract['servers'][0]['url']);
    }

    public function testTheRegistrationAddsOneCallbackAndCallsNoClosure(): void
    {
        CoreFiles::resetHooks();
        $called = false;

        JsonApi::register(static function () use (&$called): Api {
            $called = true;

            return self::api();
        });

        self::assertFalse($called);
        self::assertSame(['rest_api_init'], array_keys((array) ($GLOBALS['wp_filter'] ?? null)));
    }

    /**
     * the second registration of one API is refused by `Api::hooks()`,
     * so the closure's API is the one asked for its hooks
     */
    public function testRestApiInitAsksTheApiOfTheClosureForItsHooks(): void
    {
        CoreFiles::resetHooks();
        $api = self::api();
        $api->hooks();

        JsonApi::register(static function () use ($api): Api {
            return $api;
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the API "the pets api" is registered with WordPress already');

        do_action('rest_api_init');
    }

    public function testAClosureThatReturnsNoApiIsRefused(): void
    {
        CoreFiles::resetHooks();

        JsonApi::register(self::untyped(static function (): stdClass {
            return new stdClass();
        }));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the closure JsonApi::register() calls returns an Api, got stdClass');

        do_action('rest_api_init');
    }

    /**
     * hides the signature from static analysis,
     * so a closure that returns no `Api` reaches `register()` as a project's closure can
     */
    private static function untyped(Closure $closure): Closure
    {
        return $closure;
    }

    private static function api(): Api
    {
        return JsonApi::api(PetsApi::NAME, PetsApi::VERSION, PetsApi::REST_NAMESPACE)
            ->resource(PetsApi::pets(PetsApi::store(), Implementation::instance(PetsApi::permission(true))))
            ->build();
    }
}
