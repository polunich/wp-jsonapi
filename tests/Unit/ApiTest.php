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
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\AllowAny;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Api;
use Polunich\WpJsonApi\ApiBuilder;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Declaration\Implementation;
use Polunich\WpJsonApi\Declaration\ResourceType;
use Polunich\WpJsonApi\Declaration\ResponseShape;
use Polunich\WpJsonApi\Endpoint\Handler as EndpointHandler;
use Polunich\WpJsonApi\JsonApi;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Tests\Fixtures\Api\PetsApi;
use Polunich\WpJsonApi\Tests\Fixtures\Store\StoredPet;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\CoreFiles;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(Api::class)]
final class ApiTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        if (function_exists('add_action')) {
            CoreFiles::resetHooks();
        }
    }

    public function testTheClassIsAPublicFinalClassBuiltByTheBuilder(): void
    {
        $class = new ReflectionClass(Api::class);

        self::assertTrue($class->isFinal());
        self::assertDoesNotMatchRegularExpression('/@internal\b/', (string) $class->getDocComment());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getConstructor()?->getDocComment());
    }

    /**
     * a process of its own: other tests load the functions of WordPress
     */
    #[RunInSeparateProcess]
    public function testTheContractIsBuiltWhereWordPressIsNotLoaded(): void
    {
        foreach ([
            'add_action',
            'rest_url',
            'is_user_logged_in',
            'wp_is_application_passwords_available',
            'register_rest_route',
        ] as $function) {
            self::assertFalse(function_exists($function), $function . ' is defined');
        }

        $contract = self::builder()->build()->openApiJson();

        self::assertJson($contract);
        self::assertStringContainsString('"title": "the pets api"', $contract);
    }

    /**
     * `JsonApi::register()` adds them when WordPress prepares a REST server
     */
    public function testTheHooksAreComposedAndNoneIsAdded(): void
    {
        CoreFiles::resetHooks();

        self::builder()->build()->hooks();

        self::assertSame([], $GLOBALS['wp_filter'] ?? null);
    }

    public function testASecondRegistrationIsRefused(): void
    {
        $api = self::builder()->build();
        $api->hooks();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the API "the pets api" is registered with WordPress already');

        $api->hooks();
    }

    /**
     * @param Closure(ApiBuilder): ApiBuilder $declare
     */
    #[DataProvider('refusedReferences')]
    public function testARefusedReferenceIsRefusedBeforeTheHooksAreComposed(Closure $declare, string $message): void
    {
        $api = $declare(JsonApi::api(PetsApi::NAME, PetsApi::VERSION, PetsApi::REST_NAMESPACE))->build();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        $api->hooks();
    }

    /**
     * @return iterable<string, array{Closure(ApiBuilder): ApiBuilder, string}>
     */
    public static function refusedReferences(): iterable
    {
        yield 'an entry without a container' => [
            static function (ApiBuilder $builder): ApiBuilder {
                return $builder->resource(self::pets(loader: Implementation::service('store')));
            },
            'the resource_loader of the type "pets" of the API "the pets api" is the container entry "store", and'
            . ' the API was built without a container',
        ];
        yield 'an entry the container does not hold' => [
            static function (ApiBuilder $builder): ApiBuilder {
                return $builder->resource(self::pets(loader: Implementation::service(
                    'store',
                )))->container(self::container([]));
            },
            'is the container entry "store", which the container does not hold',
        ];
        yield 'an instance of no port it is bound to' => [
            static function (ApiBuilder $builder): ApiBuilder {
                return $builder->resource(self::pets(loader: Implementation::instance(new stdClass())));
            },
            'the resource_loader of the type "pets" of the API "the pets api" is stdClass, and an implementation'
            . ' is an instance of the port it is bound to',
        ];
        yield 'a permission of no permission' => [
            static function (ApiBuilder $builder): ApiBuilder {
                return $builder->resource(self::pets(permission: Implementation::instance(PetsApi::store())));
            },
            'the permission of fetch_resource on the type "pets"',
        ];
        yield 'an endpoint handler of no endpoint handler' => [
            static function (ApiBuilder $builder): ApiBuilder {
                $builder->resource(self::pets())->get('/stats', Implementation::instance(new stdClass()))
                    ->name('stats')
                    ->responds(ResponseShape::noContent())
                    ->permission(Implementation::instance(new AllowAny()));

                return $builder;
            },
            'the handler of the endpoint "stats" of the API "the pets api" is stdClass, and an implementation is an'
            . ' instance of the port it is bound to, '
                . EndpointHandler::class,
        ];
        yield 'an endpoint handler without a container' => [
            static function (ApiBuilder $builder): ApiBuilder {
                $builder->resource(self::pets())->get('/stats', Implementation::service('stats'))
                    ->name('stats')
                    ->responds(ResponseShape::noContent())
                    ->permission(Implementation::instance(PetsApi::permission(true)));

                return $builder;
            },
            'the handler of the endpoint "stats" of the API "the pets api" is the container entry "stats", and the'
            . ' API was built without a container',
        ];
        yield 'an endpoint permission of no endpoint port' => [
            static function (ApiBuilder $builder): ApiBuilder {
                $builder->resource(self::pets())->container(self::container(['stats' => new stdClass()]))->get(
                    '/stats',
                    Implementation::service('stats'),
                )
                    ->name('stats')
                    ->responds(ResponseShape::noContent())
                    ->permission(Implementation::instance(new readonly class implements OperationPermission {
                        #[Override]
                        public function allows(OperationAttempt $attempt): bool
                        {
                            return false;
                        }
                    }));

                return $builder;
            },
            'the permission of the endpoint "stats" of the API "the pets api" is '
                . OperationPermission::class
                . '@anonymous',
        ];
        yield 'a transaction boundary without a container' => [
            static function (ApiBuilder $builder): ApiBuilder {
                $builder->atomic('/operations')->name('atomicOperations');

                return $builder->resource(self::pets())->transactionBoundary(Implementation::service('transactions'));
            },
            'the transaction boundary of the API "the pets api" is the container entry "transactions"',
        ];
    }

    public function testAnEntryTheContainerHoldsPassesAndAFactoryIsNotCalled(): void
    {
        $called = false;
        $api = JsonApi::api(PetsApi::NAME, PetsApi::VERSION, PetsApi::REST_NAMESPACE)
            ->resource(self::pets(
                loader: Implementation::service('store'),
                permission: Implementation::factory(static function () use (&$called): object {
                    $called = true;

                    return PetsApi::permission(true);
                }),
            ))
            ->container(self::container(['store' => PetsApi::store()]))
            ->build();

        $api->hooks();

        self::assertFalse($called);
    }

    /**
     * WordPress matches a route case-insensitively,
     * so `pets` and `Pets` collide there but not in the contract
     */
    public function testTypesWordPressCannotTellApartAreRefusedAtRegistration(): void
    {
        $api = JsonApi::api(PetsApi::NAME, PetsApi::VERSION, PetsApi::REST_NAMESPACE)
            ->resource(self::pets())
            ->resource(self::pets('Pets'))
            ->build();

        self::assertJson($api->openApiJson());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the routes "/pets" and "/Pets" both respond to GET, and WordPress matches a route ignoring letter case',
        );

        $api->hooks();
    }

    /**
     * `ApiMetadata` refuses only an empty name, so a message escapes it
     */
    public function testASecondRegistrationNamesTheApiEscaped(): void
    {
        $api = JsonApi::api("the\npets", PetsApi::VERSION, PetsApi::REST_NAMESPACE)->resource(self::pets())->build();
        $api->hooks();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the API "the\\npets" is registered with WordPress already');

        $api->hooks();
    }

    private static function builder(): ApiBuilder
    {
        return JsonApi::api(PetsApi::NAME, PetsApi::VERSION, PetsApi::REST_NAMESPACE)->resource(self::pets());
    }

    private static function pets(
        string $type = 'pets',
        ?Implementation $loader = null,
        ?Implementation $permission = null,
    ): ResourceType {
        return ResourceType::make($type)
            ->id(new DigitsCodec(), static function (StoredPet $pet): string {
                return $pet->id;
            })
            ->loader($loader ?? Implementation::instance(PetsApi::store()))
            ->permission(
                OperationKind::FetchResource,
                $permission ?? Implementation::instance(PetsApi::permission(true)),
            );
    }

    /**
     * @param array<string, object> $entries
     */
    private static function container(array $entries): ContainerInterface
    {
        return new readonly class ($entries) implements ContainerInterface {
            /**
             * @param array<string, object> $entries
             */
            public function __construct(private array $entries) {}

            #[Override]
            public function get(string $id): mixed
            {
                return $this->entries[$id] ?? throw new OutOfBoundsException($id);
            }

            #[Override]
            public function has(string $id): bool
            {
                return array_key_exists($id, $this->entries);
            }
        };
    }
}
