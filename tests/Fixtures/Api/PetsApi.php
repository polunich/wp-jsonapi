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

namespace Polunich\WpJsonApi\Tests\Fixtures\Api;

use Override;
use Polunich\WpJsonApi\Access\AllowAny;
use Polunich\WpJsonApi\Access\EndpointAttempt;
use Polunich\WpJsonApi\Access\EndpointPermission;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\ChallengeProvider;
use Polunich\WpJsonApi\Authentication\Identity;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Declaration\Attribute;
use Polunich\WpJsonApi\Declaration\Implementation;
use Polunich\WpJsonApi\Declaration\ResourceType;
use Polunich\WpJsonApi\Execution\ContainerImplementationResolver;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStore;
use Polunich\WpJsonApi\Tests\Fixtures\Store\StoredPet;
use Polunich\WpJsonApi\WordPress\RestUrlGenerator;
use Psr\Log\LoggerInterface;

/**
 * a small pets API declared with the public builders.
 * anyone reads, and a delete asks the permission the test gives,
 * so a test gets a refusal or a 204 with no link to write
 *
 * @internal
 */
final class PetsApi
{
    public const NAME = 'the pets api';

    public const VERSION = '1.0.0';

    public const REST_NAMESPACE = 'acme/v1';

    /** the REST root of a site with pretty permalinks */
    public const REST_ROOT = 'https://example.com/wp-json';

    private function __construct() {}

    public static function store(): PetStore
    {
        return new PetStore([new StoredPet('1', '1', 'first'), new StoredPet('2', '1', 'second')]);
    }

    public static function pets(PetStore $store, Implementation $deletePermission): ResourceType
    {
        $anyone = Implementation::instance(new AllowAny());

        return ResourceType::make(PetStore::TYPE)
            ->id(new DigitsCodec(), static function (StoredPet $pet): string {
                return $pet->id;
            })
            ->attribute(Attribute::make('name', new StringCodec())->accessor(static function (StoredPet $pet): string {
                return $pet->name;
            }))
            ->reader(Implementation::instance($store))
            ->loader(Implementation::instance($store))
            ->deleter(Implementation::instance($store))
            ->permission(OperationKind::FetchCollection, $anyone)
            ->permission(OperationKind::FetchResource, $anyone)
            ->permission(OperationKind::DeleteResource, $deletePermission);
    }

    /**
     * links come from the URL generator of the adapter,
     * over a `rest_url()` stand-in that prefixes `REST_ROOT`
     *
     * @param list<Challenge> $challenges
     */
    public static function server(
        PetStore $store,
        OperationPermission $deletePermission,
        LoggerInterface $logger,
        bool $authenticated = true,
        array $challenges = [],
    ): Server {
        return Server::compose(
            self::metadata($store, $deletePermission),
            new ContainerImplementationResolver(),
            new RestUrlGenerator(self::REST_NAMESPACE, static function (string $path): string {
                return self::REST_ROOT . $path;
            }),
            self::identity($authenticated),
            self::challengeProvider($challenges),
            null,
            null,
            [],
            false,
            $logger,
        );
    }

    public static function identity(bool $authenticated): Identity
    {
        return new readonly class ($authenticated) implements Identity {
            public function __construct(private bool $authenticated) {}

            #[Override]
            public function isAuthenticated(): bool
            {
                return $this->authenticated;
            }
        };
    }

    /**
     * @param list<Challenge> $challenges
     */
    public static function challengeProvider(array $challenges): ChallengeProvider
    {
        return new readonly class ($challenges) implements ChallengeProvider {
            /**
             * @param list<Challenge> $challenges
             */
            public function __construct(private array $challenges) {}

            /**
             * @return list<Challenge>
             */
            #[Override]
            public function challenges(ServerRequest $request, array $schemes): array
            {
                return $this->challenges;
            }
        };
    }

    public static function permission(bool $allows): OperationPermission&EndpointPermission
    {
        return $allows ? new AllowAny() : new readonly class implements OperationPermission, EndpointPermission {
            #[Override]
            public function allows(OperationAttempt|EndpointAttempt $attempt): bool
            {
                return false;
            }
        };
    }

    /**
     * @param ?list<SecurityScheme> $deleteSchemes those the delete declares;
     *                                            null inherits the API's
     */
    public static function metadata(
        PetStore $store,
        OperationPermission $deletePermission,
        ?array $deleteSchemes = null,
    ): ApiMetadata {
        $pets = self::pets($store, Implementation::instance($deletePermission));

        if ($deleteSchemes !== null) {
            $pets->securitySchemes(OperationKind::DeleteResource, ...$deleteSchemes);
        }

        return new ApiMetadata(
            self::NAME,
            self::VERSION,
            self::REST_NAMESPACE,
            new ResourceRegistry([$pets->build()]),
            [],
            [],
            Samples::operators(),
            [],
            null,
            Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );
    }
}
