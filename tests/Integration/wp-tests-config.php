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

/*
 * the `wp-config.php` of the integration suite,
 * plus the site domain, e-mail and title the test installer reads.
 * the database and `ABSPATH` come from the environment;
 * a missing variable stops the run with its name.
 *
 * it also sets the server variables of a command line request,
 * since WordPress reads the host of the site from them while it installs and while it builds a URL
 */

$wpJsonApiEnvironment = static function (string $name): string {
    $value = getenv($name);

    if (!is_string($value) || $value === '') {
        fwrite(STDERR, sprintf("the integration suite reads %s from the environment, and it is not set\n", $name));

        exit(1);
    }

    return $value;
};

define('DB_NAME', $wpJsonApiEnvironment('WP_JSONAPI_TEST_DB_NAME'));
define('DB_USER', $wpJsonApiEnvironment('WP_JSONAPI_TEST_DB_USER'));
define('DB_PASSWORD', $wpJsonApiEnvironment('WP_JSONAPI_TEST_DB_PASSWORD'));
define('DB_HOST', $wpJsonApiEnvironment('WP_JSONAPI_TEST_DB_HOST'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// fixed keys and salts: a test site signs nothing a client keeps
define('AUTH_KEY', 'wp-jsonapi integration auth key');
define('SECURE_AUTH_KEY', 'wp-jsonapi integration secure auth key');
define('LOGGED_IN_KEY', 'wp-jsonapi integration logged in key');
define('NONCE_KEY', 'wp-jsonapi integration nonce key');
define('AUTH_SALT', 'wp-jsonapi integration auth salt');
define('SECURE_AUTH_SALT', 'wp-jsonapi integration secure auth salt');
define('LOGGED_IN_SALT', 'wp-jsonapi integration logged in salt');
define('NONCE_SALT', 'wp-jsonapi integration nonce salt');

$table_prefix = 'wptests_';

define('WP_DEBUG', true);
define('DISABLE_WP_CRON', true);

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'wp-jsonapi integration');

define('ABSPATH', rtrim($wpJsonApiEnvironment('WP_JSONAPI_WORDPRESS_DIR'), '/') . '/');

unset($wpJsonApiEnvironment);

$_SERVER['HTTP_HOST'] = WP_TESTS_DOMAIN;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '';
$_SERVER['SERVER_NAME'] = WP_TESTS_DOMAIN;
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['PHP_SELF'] = '/index.php';
unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTPS']);
