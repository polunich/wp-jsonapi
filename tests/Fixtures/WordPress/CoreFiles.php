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

namespace Polunich\WpJsonApi\Tests\Fixtures\WordPress;

use Composer\InstalledVersions;
use LogicException;
use WP_REST_Server;

/**
 * the real WordPress files the unit tests of the adapter run against:
 * the plugin API and the value classes of the REST API, from `roots/wordpress-no-content`.
 *
 * no double stands in for them. a class or function declared in the analysed code
 * under a WordPress name replaces the PHPStan stub for the whole analysis, src included,
 * and a test against a double pins what the double does, not what WordPress does.
 * these files run without a database; the functions that need a site,
 * `register_rest_route()`, `rest_url()`, `is_user_logged_in()`
 * and `wp_is_application_passwords_available()`, reach the adapter through injected closures.
 *
 * they are loaded on first use, never by the bootstrap,
 * so a test that proves a path free of WordPress can run in a process of its own
 * and find no WordPress function there
 *
 * @internal
 */
final class CoreFiles
{
    private const PACKAGE = 'roots/wordpress-no-content';

    /**
     * in the order `wp-settings.php` loads them.
     * `functions.php` requires `option.php` through `ABSPATH` and `WPINC`,
     * and `plugin.php` requires the classes of the hook API itself
     */
    private const FILES = [
        'wp-includes/load.php',
        'wp-includes/plugin.php',
        'wp-includes/functions.php',
        'wp-includes/class-wp-error.php',
        'wp-includes/class-wp-http-response.php',
        'wp-includes/rest-api/class-wp-rest-server.php',
        'wp-includes/rest-api/class-wp-rest-response.php',
        'wp-includes/rest-api/class-wp-rest-request.php',
    ];

    private function __construct() {}

    /**
     * @throws LogicException when the package is not installed
     */
    public static function load(): void
    {
        if (class_exists(WP_REST_Server::class, false)) {
            return;
        }

        $root = InstalledVersions::getInstallPath(self::PACKAGE)
            ?? throw new LogicException('the unit tests of the WordPress'
                . ' adapter need ' . self::PACKAGE . ', which is not installed');

        if (!defined('ABSPATH')) {
            define('ABSPATH', rtrim($root, '/') . '/');
        }

        if (!defined('WPINC')) {
            define('WPINC', 'wp-includes');
        }

        foreach (self::FILES as $file) {
            require_once ABSPATH . $file;
        }
    }

    /**
     * drops every callback an earlier test added: WordPress keeps all hooks in these four globals
     */
    public static function resetHooks(): void
    {
        self::load();

        $GLOBALS['wp_filter'] = [];
        $GLOBALS['wp_actions'] = [];
        $GLOBALS['wp_filters'] = [];
        $GLOBALS['wp_current_filter'] = [];
    }
}
