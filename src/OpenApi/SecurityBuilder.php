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

namespace Polunich\WpJsonApi\OpenApi;

use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use stdClass;

/**
 * a list of requirements, the top-level one or that of an operation declaring its own schemes,
 * ends with the empty requirement `{}`, anonymous access:
 * permissions are decided per request,
 * so the contract cannot tell which operation needs credentials.
 * any one requirement of the list suffices, so their order carries no meaning.
 * a requirement lists no scopes, since the library declares none
 *
 * @internal
 */
final readonly class SecurityBuilder
{
    public function __construct(private ApiMetadata $api) {}

    public function securitySchemes(): stdClass
    {
        $schemes = [];

        foreach ($this->api->componentSecuritySchemes as $scheme) {
            $schemes[$scheme->name] = (object) $scheme->openApi;
        }

        return (object) $schemes;
    }

    /**
     * @return list<stdClass>
     */
    public function security(): array
    {
        return self::requirements($this->api->securitySchemes);
    }

    /**
     * @param list<SecurityScheme> $schemes
     *
     * @return list<stdClass>
     */
    public static function requirements(array $schemes): array
    {
        $requirements = [];

        foreach ($schemes as $scheme) {
            $requirements[] = (object) [$scheme->name => []];
        }

        $requirements[] = new stdClass();

        return $requirements;
    }
}
