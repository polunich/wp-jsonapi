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

namespace Polunich\WpJsonApi\Tests\Unit\Endpoint;

use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Endpoint\BytesBody;
use Polunich\WpJsonApi\Endpoint\FormBody;
use Polunich\WpJsonApi\Endpoint\JsonBody;
use Polunich\WpJsonApi\Endpoint\MetaBody;
use Polunich\WpJsonApi\Endpoint\Request;
use Polunich\WpJsonApi\Endpoint\ResourceBody;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Store\CollectionQuery;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

/**
 * @internal
 */
#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    public function testTheValueIsPublicFinalAndReadonly(): void
    {
        $class = new ReflectionClass(Request::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertDoesNotMatchRegularExpression('/^\s*\*\s*@internal\b/m', (string) $class->getDocComment());
    }

    public function testTheRequestCarriesEveryPartAHandlerReads(): void
    {
        self::assertSame(
            [
                ['endpointName', 'string'],
                ['path', 'array'],
                ['records', 'array'],
                ['queryParameters', Values::class],
                ['collectionQuery', '?' . CollectionQuery::class],
                [
                    'body',
                    implode(
                        '|',
                        [
                            ResourceBody::class,
                            Linkage::class,
                            MetaBody::class,
                            JsonBody::class,
                            FormBody::class,
                            BytesBody::class,
                            'null',
                        ],
                    ),
                ],
                ['languages', LanguagePreferences::class],
                ['precondition', '?' . Precondition::class],
                ['http', ServerRequest::class],
                ['mediaType', 'string'],
                ['bodyMediaType', '?string'],
            ],
            array_map(static function (ReflectionProperty $property): array {
                return [$property->getName(), (string) $property->getType()];
            }, (new ReflectionClass(Request::class))->getProperties(ReflectionProperty::IS_PUBLIC)),
        );
    }

    public function testARecordIsReadByItsParameter(): void
    {
        $pet = new stdClass();

        self::assertSame($pet, self::request(['id' => $pet])->record('id'));
    }

    public function testAParameterBoundToNoTypeHasNoRecord(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('the endpoint "adoptPet" binds no record to the parameter "owner"');

        self::request([])->record('owner');
    }

    /**
     * @param array<string, object> $records
     */
    private static function request(array $records): Request
    {
        return new Request(
            'adoptPet',
            ['id' => '1'],
            $records,
            new Values([]),
            null,
            null,
            new LanguagePreferences([]),
            null,
            new ServerRequest('POST', new Headers(), [], ['id' => '1'], ''),
            'application/vnd.api+json',
            null,
        );
    }
}
