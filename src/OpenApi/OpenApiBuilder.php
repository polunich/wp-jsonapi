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

use JsonException;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use stdClass;

/**
 * builds the OpenAPI document of one API from its declarations alone, never from the running site,
 * so one declaration gives one contract on every machine.
 *
 * members follow the fixed-field order of the OpenAPI Object and of the Components Object.
 * across the module, every map keyed by declared names is a `stdClass`:
 * a type, a relationship or a scheme may be named with digits,
 * and `json_encode` writes a PHP array keyed 0, 1, ... as a JSON array
 *
 * @internal
 */
final readonly class OpenApiBuilder
{
    private const OPENAPI_VERSION = '3.1.2';

    private const SITE = 'site';

    private const REST_PREFIX = 'restPrefix';

    /**
     * the prefix WordPress serves the REST API under unless the `rest_url_prefix` filter changes it
     */
    private const DEFAULT_REST_PREFIX = 'wp-json';

    /**
     * a placeholder: OpenAPI requires a default for every server variable,
     * and one declaration serves every site
     */
    private const DEFAULT_SITE = 'http://localhost';

    private PathBuilder $paths;

    private DocumentSchemaBuilder $documents;

    private ParameterBuilder $parameters;

    private SecurityBuilder $security;

    private HeaderBuilder $headers;

    private TagBuilder $tags;

    private AtomicSchemaBuilder $atomic;

    private JsonEncoder $encoder;

    public function __construct(private ApiMetadata $api)
    {
        $this->paths = new PathBuilder($api);
        $this->documents = new DocumentSchemaBuilder($api);
        $this->parameters = new ParameterBuilder($api);
        $this->security = new SecurityBuilder($api);
        $this->headers = new HeaderBuilder($api);
        $this->tags = new TagBuilder($api);
        $this->atomic = new AtomicSchemaBuilder($api);
        $this->encoder = new JsonEncoder();
    }

    public function document(): stdClass
    {
        $document = [
            'openapi' => self::OPENAPI_VERSION,
            'info' => (object) ['title' => $this->api->name, 'version' => $this->api->version],
            'servers' => [$this->server()],
            'paths' => (object) $this->paths->paths(),
            'components' => (object) $this->components(),
            'security' => $this->security->security(),
        ];
        $tags = $this->tags->tags();

        if ($tags !== []) {
            $document['tags'] = $tags;
        }

        return (object) $document;
    }

    /**
     * @throws JsonException when a declared string is not valid UTF-8
     */
    public function json(): string
    {
        return $this->encoder->encode($this->document());
    }

    /**
     * @return array<string, stdClass>
     */
    private function components(): array
    {
        $components = ['schemas' => (object) $this->schemas()];
        $parameters = $this->parameters->languageParameters();

        if ($parameters !== []) {
            $components['parameters'] = (object) $parameters;
        }

        $components['headers'] = (object) $this->headers->headers();
        $components['securitySchemes'] = $this->security->securitySchemes();

        return $components;
    }

    /**
     * the site address and the REST prefix are server variables:
     * the declaration cannot know them, and a site may change the prefix
     */
    private function server(): stdClass
    {
        return (object) [
            'url' => sprintf(
                '{%s}/{%s}/%s',
                self::SITE,
                self::REST_PREFIX,
                self::encodeNamespace($this->api->restNamespace),
            ),
            'description' => 'the REST namespace of the API on the site it runs on. a site without pretty'
                . ' permalinks serves the same routes under the rest_route query parameter instead'
                . ' [code: wordpress-develop trunk, get_rest_url()]',
            'variables' => (object) [
                self::SITE => (object) [
                    'default' => self::DEFAULT_SITE,
                    'description' => 'the address of the site, which the declaration does not name: the'
                        . ' contract is built without WordPress',
                ],
                self::REST_PREFIX => (object) [
                    'default' => self::DEFAULT_REST_PREFIX,
                    'description' => 'the prefix the site serves the REST API under, "wp-json" unless a filter'
                        . ' replaces it [code: wordpress-develop trunk, rest_get_url_prefix()]',
                ],
            ],
        ];
    }

    /**
     * `array_merge` lets a later key overwrite an earlier one, so the sets must never collide:
     * every name derived from a declaration holds a dot, and the fixed names hold none
     *
     * @return array<string, stdClass>
     */
    private function schemas(): array
    {
        return array_merge(
            $this->documents->schemas(),
            $this->parameters->schemas(),
            $this->atomic->schemas(),
        );
    }

    private static function encodeNamespace(string $restNamespace): string
    {
        return implode(
            '/',
            array_map(
                static function (string $segment): string {
                    return rawurlencode($segment);
                },
                explode('/', $restNamespace),
            ),
        );
    }
}
