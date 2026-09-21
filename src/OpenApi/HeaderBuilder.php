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

use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use stdClass;

/**
 * builds `components.headers`: the header fields the responses of the contract reference
 *
 * @internal
 */
final readonly class HeaderBuilder
{
    public const REFERENCE = '#/components/headers/';

    public function __construct(private ApiMetadata $api) {}

    /**
     * the same set for every API: a header nothing references has no effect
     *
     * @return array<string, stdClass>
     */
    public function headers(): array
    {
        return [
            FieldName::CONTENT_LANGUAGE => self::header($this->contentLanguageText()),
            FieldName::VARY => self::header($this->varyText()),
            FieldName::ETAG => self::header(
                'the entity tag of the selected representation, which a conditional write sends back in'
                . ' If-Match. a response to a request with include carries none, since its query'
                . ' selects another representation',
            ),
            FieldName::LOCATION => self::header(
                'the self link of the resource the request created [spec, Creating Resources]',
                true,
            ),
            FieldName::CONTENT_LOCATION => self::header(
                'the self link of the resource that monitors the accepted write [primary: RFC 9110'
                . ' 15.3.3]',
                true,
            ),
            FieldName::WWW_AUTHENTICATE => self::header(
                'the authentication schemes that apply to this request, at least one of them [primary:'
                . ' RFC 9110 15.5.2]',
                true,
            ),
            FieldName::ACCEPT => self::header(
                'the media types of the content the route reads, which "can be used to indicate which media types'
                . ' would have been accepted in the request" [primary: RFC 9110 15.5.16]',
                true,
            ),
            FieldName::CONTENT_DISPOSITION => self::header(
                'attachment, with the file name the client saves the content under, where the endpoint names one'
                . ' [primary: RFC 6266 4]',
            ),
            FieldName::CONTENT_LENGTH => self::header(
                'the octets of the content, where its size is known before it is sent and nothing between the site'
                . ' and the client changes it [primary: RFC 9110 8.6]',
            ),
        ];
    }

    private function contentLanguageText(): string
    {
        $sources = $this->api->languageSources->sources;

        if ($sources === []) {
            return 'the language of the representation, which the API chooses without reading the request [primary:'
                . ' RFC 9110 8.5]';
        }

        return sprintf(
            'the language of the representation, chosen from %s [primary: RFC 9110 8.5]',
            implode(', else from ', array_map(static function (LanguageSourceMetadata $source): string {
                return $source->queryParameter === null
                    ? 'the language ranges of ' . FieldName::ACCEPT_LANGUAGE
                    : 'the language range of the query parameter ' . $source->queryParameter;
            }, $sources)),
        );
    }

    /**
     * `Accept`, and `Accept-Language` where no source before it stands in the request:
     * a query parameter is part of the target URI, which `Vary` never names (RFC 9110 12.5.5)
     */
    private function varyText(): string
    {
        $before = [];
        $fields = FieldName::ACCEPT;

        foreach ($this->api->languageSources->sources as $source) {
            if ($source->queryParameter === null) {
                $fields .= $before === []
                    ? ' and ' . FieldName::ACCEPT_LANGUAGE
                    : sprintf(
                        ', and %s where the request does not carry %s',
                        FieldName::ACCEPT_LANGUAGE,
                        ParameterBuilder::listing(array_map(ParameterBuilder::sourceName(...), $before), 'or'),
                    );

                break;
            }

            $before[] = $source;
        }

        return sprintf(
            'the request fields the selected representation depends on: %s [primary: RFC 9110 12.5.5]',
            $fields,
        );
    }

    /**
     * `$required` for a field every response that references it carries;
     * a field that depends on the request stays optional
     */
    private static function header(string $description, bool $required = false): stdClass
    {
        $header = ['description' => $description];

        if ($required) {
            $header['required'] = true;
        }

        $header['schema'] = (object) ['type' => 'string'];

        return (object) $header;
    }
}
