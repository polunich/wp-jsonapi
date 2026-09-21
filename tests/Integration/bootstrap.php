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
 * boots WordPress in the PHPUnit process; `wp-settings.php` loads the must-use fixture plugin.
 * the site is installed beforehand by `tests/Integration/install.php`.
 *
 * the REST server is the recording one, set before any request creates a server.
 * auth cookies stay unsent: output has started by the time a test logs a user in
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

\Polunich\WpJsonApi\Tests\Integration\OutputCapture::install();
require_once __DIR__ . '/wp-tests-config.php';
require_once ABSPATH . 'wp-settings.php';

add_filter('wp_rest_server_class', static function (): string {
    return \Polunich\WpJsonApi\Tests\Fixtures\WordPress\RecordingRestServer::class;
});
add_filter('send_auth_cookies', '__return_false');
// `wp_ob_end_flush_all()` would end the output capture too,
// which raises a notice since that buffer is not removable
remove_action('shutdown', 'wp_ob_end_flush_all', 1);
