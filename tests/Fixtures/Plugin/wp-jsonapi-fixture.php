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
 * the must-use plugin of the integration suite, copied into `wp-content/mu-plugins`:
 * it loads the autoloader of the repository `WP_JSONAPI_ROOT` names, then the fixture plugin.
 *
 * it runs in a closure of its own: `wp-settings.php` includes it in the scope it runs in itself,
 * a function of PHPUnit in the suite
 */

(static function (): void {
    $root = getenv('WP_JSONAPI_ROOT');

    if (!is_string($root) || $root === '') {
        throw new LogicException('the fixture plugin of wp-jsonapi reads the repository from WP_JSONAPI_ROOT, which'
            . ' is not set');
    }

    require_once rtrim($root, '/') . '/vendor/autoload.php';

    \Polunich\WpJsonApi\Tests\Fixtures\Plugin\FixturePlugin::load();
})();
