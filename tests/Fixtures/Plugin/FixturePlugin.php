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

namespace Polunich\WpJsonApi\Tests\Fixtures\Plugin;

use LogicException;
use Override;
use Polunich\WpJsonApi\Access\EndpointAttempt;
use Polunich\WpJsonApi\Access\EndpointPermission;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Api;
use Polunich\WpJsonApi\JsonApi;
use Polunich\WpJsonApi\Tests\Fixtures\Api\FixtureApi;
use Polunich\WpJsonApi\Tests\Fixtures\Api\InMemoryStore;

/**
 * the fixture API over the seeded store,
 * registered as the plugin loads, as a project registers its API.
 *
 * the write permission allows any logged-in user,
 * so the user WordPress authenticates decides who writes.
 * the store lives as long as the process, so each test resets it before it runs
 *
 * @internal
 */
final class FixturePlugin
{
    private static ?InMemoryStore $store = null;

    private static int $builds = 0;

    private function __construct() {}

    /**
     * @throws LogicException when the plugin is loaded twice
     */
    public static function load(): void
    {
        if (self::$store !== null) {
            throw new LogicException('the fixture plugin is loaded twice');
        }

        $store = InMemoryStore::seeded();
        self::$store = $store;

        JsonApi::register(static function () use ($store): Api {
            ++self::$builds;

            return FixtureApi::define($store, self::loggedInUsers())->build();
        });
    }

    /**
     * how many times the registration built the API in this process
     */
    public static function builds(): int
    {
        return self::$builds;
    }

    /**
     * @throws LogicException when the plugin is not loaded
     */
    public static function store(): InMemoryStore
    {
        return self::$store
            ?? throw new LogicException('the fixture plugin is not loaded: copy it into wp-content/mu-plugins');
    }

    private static function loggedInUsers(): OperationPermission&EndpointPermission
    {
        return new readonly class implements OperationPermission, EndpointPermission {
            #[Override]
            public function allows(OperationAttempt|EndpointAttempt $attempt): bool
            {
                return is_user_logged_in();
            }
        };
    }
}
