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
 * installs WordPress for the integration suite:
 *
 *     php tests/Integration/install.php <wordpress-directory>
 *
 * it follows the installer of WordPress's own test suite:
 * it drops every table of the site and of a network, forgets the roles read from them,
 * and calls `wp_install()` with the user `admin` and the password `password`.
 * a permalink structure is prefilled while the options are populated and deleted afterwards,
 * so `wp_install_maybe_enable_pretty_permalinks()` probes no URL of a site that serves none;
 * the site keeps plain permalinks. `pre_wp_mail` stops the e-mail to the new administrator.
 * the MySQL-only statements of that installer, the storage engine and the foreign key checks,
 * are left out: nothing here depends on the engine, and no WordPress table declares a foreign key.
 *
 * where `WP_JSONAPI_WORDPRESS_DIR` is set, the directory must be that WordPress,
 * so the tables dropped are those of the site the suite then runs in
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$wpJsonApiDirectory = $argv[1] ?? '';

if ($wpJsonApiDirectory === '') {
    fwrite(STDERR, "usage: php tests/Integration/install.php <wordpress-directory>\n");

    exit(2);
}

$wpJsonApiConfigured = getenv('WP_JSONAPI_WORDPRESS_DIR');

if (
    is_string($wpJsonApiConfigured)
    && $wpJsonApiConfigured !== ''
    && realpath($wpJsonApiConfigured) !== realpath($wpJsonApiDirectory)
) {
    fwrite(STDERR, sprintf(
        "the directory %s is not the WordPress of WP_JSONAPI_WORDPRESS_DIR, %s\n",
        $wpJsonApiDirectory,
        $wpJsonApiConfigured,
    ));

    exit(2);
}

putenv('WP_JSONAPI_WORDPRESS_DIR=' . $wpJsonApiDirectory);

error_reporting(E_ALL & ~E_DEPRECATED);

define('WP_INSTALLING', true);

require __DIR__ . '/wp-tests-config.php';
require_once ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

echo "installing WordPress into the database of the integration suite\n";

$wpJsonApiDatabase = $GLOBALS['wpdb'] ?? null;

if (!$wpJsonApiDatabase instanceof wpdb) {
    fwrite(STDERR, "wp-settings.php left no database object in \$wpdb\n");

    exit(1);
}

foreach ([...$wpJsonApiDatabase->tables(), ...$wpJsonApiDatabase->tables('ms_global')] as $wpJsonApiTable) {
    $wpJsonApiDatabase->query('DROP TABLE IF EXISTS ' . $wpJsonApiTable);
}

// the roles were read from the tables just dropped,
// and `populate_roles()` skips a role that is loaded already
unset($GLOBALS['wp_roles']);

$wpJsonApiPermalinks = static function (): void {
    update_option('permalink_structure', '/%year%/%monthnum%/%day%/%postname%/');
};

add_action('populate_options', $wpJsonApiPermalinks);
add_filter('pre_wp_mail', '__return_false');

wp_install(WP_TESTS_TITLE, 'admin', WP_TESTS_EMAIL, true, '', 'password');

delete_option('permalink_structure');
remove_action('populate_options', $wpJsonApiPermalinks);

echo "installed\n";
