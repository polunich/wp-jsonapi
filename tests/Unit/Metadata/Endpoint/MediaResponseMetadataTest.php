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

namespace Polunich\WpJsonApi\Tests\Unit\Metadata\Endpoint;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaResponseMetadata;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(MediaResponseMetadata::class)]
final class MediaResponseMetadataTest extends TestCase
{
    public function testTheClassIsInternalFinalAndReadonly(): void
    {
        $class = new ReflectionClass(MediaResponseMetadata::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testAMediaResponseIsBytesOrAJsonValue(): void
    {
        $csv = new MediaResponseMetadata(self::mediaType('text/csv; header=present'), null);
        $json = new MediaResponseMetadata(self::mediaType('application/problem+json'), new IntegerCodec());

        self::assertSame('text/csv;header=present', $csv->mediaType->toHeaderValue());
        self::assertNull($csv->codec);
        self::assertInstanceOf(IntegerCodec::class, $json->codec);
    }

    #[DataProvider('refusedMediaResponses')]
    public function testAMediaResponseIsRefusedWhereItsMediaTypeDoesNotFit(
        string $mediaType,
        bool $json,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new MediaResponseMetadata(self::mediaType($mediaType), $json ? new IntegerCodec() : null);
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function refusedMediaResponses(): iterable
    {
        yield 'a range' => [
            'text/*',
            false,
            'a response of 200 names the media type "text/*", and a representation has one media type, no range and'
            . ' no weight',
        ];
        yield 'the JSON:API media type' => [
            'application/vnd.api+json',
            false,
            'a response of 200 names the JSON:API media type, whose documents the shapes of the library describe',
        ];
        yield 'JSON in a media type of no JSON' => [
            'text/plain',
            true,
            'a JSON response is written in a JSON media type, application/json or one of the +json suffix, and'
            . ' "text/plain" is none',
        ];
    }

    public function testAWeightedMediaTypeIsNoRepresentation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('and a representation has one media type, no range and no weight');

        new MediaResponseMetadata(new MediaType('text', 'csv', [], 0.5), null);
    }

    public function testTheTextOfAMediaTypeIsParsedOrRefused(): void
    {
        self::assertSame('text/csv', MediaResponseMetadata::mediaType('text/csv', 'the response')->toHeaderValue());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the response names "csv", which is no media type: a type, a subtype and'
            . ' parameters (RFC 9110, section 8.3.1)');

        MediaResponseMetadata::mediaType('csv', 'the response');
    }

    private static function mediaType(string $text): MediaType
    {
        return MediaType::parse($text) ?? throw new InvalidArgumentException($text);
    }
}
