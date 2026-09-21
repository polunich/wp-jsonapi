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

namespace Polunich\WpJsonApi\Tests\Integration;

use Closure;
use Override;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Testing\JsonApiAssertions;
use Polunich\WpJsonApi\Tests\Contract\GeneratedOpenApiTest;
use Polunich\WpJsonApi\Tests\Fixtures\Api\FixtureApi;
use Polunich\WpJsonApi\Tests\Fixtures\Plugin\FixturePlugin;
use WP_Rewrite;

/**
 * each test starts with the seeded store and no user logged in,
 * the filters it adds through `filter()` are removed after it,
 * and the permalink form it sets through `permalinks()` or `rewrite()` is restored after it.
 *
 * documents are checked against the committed contract with the assertion the library ships,
 * as a project checks its own API
 *
 * @internal
 */
abstract class WordPressTestCase extends TestCase
{
    use JsonApiAssertions;

    protected const ROUTE_PREFIX = '/' . FixtureApi::REST_NAMESPACE;

    protected const JSON_API = 'application/vnd.api+json';

    /** @var list<array{string, Closure, int}> hook, callback and priority of each filter added */
    private array $filters = [];

    /** @var ?string the permalink form the test started with, null until the test changes it */
    private ?string $permalinks = null;

    /** whether the test rebuilt the rewrite rules, which the restore then rebuilds as well */
    private bool $rewritten = false;

    #[Override]
    protected function setUp(): void
    {
        FixturePlugin::store()->reset();
        wp_set_current_user(0);
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->permalinks !== null) {
            if ($this->rewritten) {
                self::rewriteRules($this->permalinks);
            }

            // `set_permalink_structure()` skips the option when `$wp_rewrite` holds the form
            update_option('permalink_structure', $this->permalinks);
            $this->permalinks = null;
            $this->rewritten = false;
        }

        foreach ($this->filters as [$hook, $callback, $priority]) {
            remove_filter($hook, $callback, $priority);
        }

        $this->filters = [];
        wp_set_current_user(0);
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
    }

    /**
     * added for the rest of the test
     */
    protected function filter(string $hook, Closure $callback, int $priority = 10, int $arguments = 1): void
    {
        add_filter($hook, $callback, $priority, $arguments);
        $this->filters[] = [$hook, $callback, $priority];
    }

    /**
     * the permalink form `rest_url()` reads, for the rest of the test
     */
    protected function permalinks(string $structure): void
    {
        $this->keepOriginalPermalinks();
        update_option('permalink_structure', $structure);
    }

    /**
     * the permalink form of the site and its rewrite rules, which `WP::parse_request()` reads,
     * where `permalinks()` changes the option `rest_url()` reads alone; for the rest of the test
     */
    protected function rewrite(string $structure): void
    {
        $this->keepOriginalPermalinks();
        $this->rewritten = true;
        self::rewriteRules($structure);
    }

    protected static function assertFetchable(string $link): void
    {
        $response = RestClient::follow($link, ['accept' => self::JSON_API]);

        self::assertSame(200, $response->status, $link . "\n" . $response->body);
        self::assertSame(self::JSON_API, $response->header('Content-Type'), $link);
    }

    /**
     * `$path` is below the namespace
     */
    protected static function assertMatchesContract(RestResponse $response, string $path, string $method): void
    {
        self::assertResponseMatchesContract(
            GeneratedOpenApiTest::CONTRACT,
            $path,
            $method,
            $response->status,
            $response->header('Content-Type'),
            $response->body,
        );
    }

    /**
     * `$path` is below the namespace; `accept` defaults to the JSON:API media type
     *
     * @param array<array-key, mixed> $query
     * @param array<string, string>   $headers
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $files
     */
    protected static function send(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        string $body = '',
        array $post = [],
        array $files = [],
    ): RestResponse {
        return RestClient::send(
            $method,
            self::ROUTE_PREFIX . $path,
            $query,
            $headers + ['accept' => self::JSON_API],
            $body,
            $post,
            $files,
        );
    }

    /**
     * the form before the first change of the test, whichever call made it:
     * a later call reads a form the test set itself
     */
    private function keepOriginalPermalinks(): void
    {
        $current = get_option('permalink_structure');
        $this->permalinks ??= is_string($current) ? $current : '';
    }

    private static function rewriteRules(string $structure): void
    {
        global $wp_rewrite;

        self::assertInstanceOf(WP_Rewrite::class, $wp_rewrite);

        $wp_rewrite->set_permalink_structure($structure);
        $wp_rewrite->flush_rules(false);
    }
}
