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

namespace Polunich\WpJsonApi\Tests\Unit\Parsing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Parsing\QueryParameterReader;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(QueryParameterReader::class)]
final class QueryParameterReaderTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(QueryParameterReader::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testEachValueIsDecodedByItsCodecInDeclarationOrder(): void
    {
        $errors = new ErrorList(new StatusSelector());

        $values = QueryParameterReader::read(
            ['priceRange' => ['min' => '10', 'max' => '50'], 'nearBy' => 'park', 'other' => 'x'],
            self::declared(),
            $errors,
        );

        self::assertNull($errors->exception());
        self::assertSame(['nearBy', 'priceRange'], $values->names());
        self::assertSame('park', $values->get('nearBy'));
        self::assertSame(['min' => 10, 'max' => 50], $values->get('priceRange'));
    }

    public function testAnOptionalParameterTheRequestLeavesOutIsAbsent(): void
    {
        $errors = new ErrorList(new StatusSelector());

        $values = QueryParameterReader::read(['priceRange' => ['min' => '1']], self::declared(), $errors);

        self::assertNull($errors->exception());
        self::assertFalse($values->has('nearBy'));
    }

    public function testEveryProblemIsCollectedAndNamesThePlaceTheClientWrote(): void
    {
        $errors = new ErrorList(new StatusSelector());

        QueryParameterReader::read(['priceRange' => ['min' => 'ten']], [
            new QueryParameterMetadata('placedAfter', new StringCodec(), true),
            ...self::declared(),
        ], $errors);

        $exception = $errors->exception();

        self::assertInstanceOf(JsonApiException::class, $exception);
        self::assertSame(400, $exception->status());
        self::assertSame(
            [
                [ErrorCode::MissingQueryParameter->value, ['name' => 'placedAfter'], 'placedAfter'],
                ['invalid_type', 'priceRange[min]'],
            ],
            array_map(static function ($description): array {
                $code = $description->code instanceof ErrorCode ? $description->code->value : $description->code;

                return $code === ErrorCode::MissingQueryParameter->value
                    ? [$code, $description->parameters, $description->parameter]
                    : [$code, $description->parameter];
            }, $exception->descriptions()),
        );
    }

    /**
     * @return list<QueryParameterMetadata>
     */
    private static function declared(): array
    {
        return [
            new QueryParameterMetadata('nearBy', new StringCodec(), false),
            new QueryParameterMetadata(
                'priceRange',
                new ObjectCodec(['min' => new IntegerCodec(), 'max' => new IntegerCodec()]),
                true,
            ),
        ];
    }
}
