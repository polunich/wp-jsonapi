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
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Metadata\Endpoint\FormFieldMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyShapeKind;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(MediaBodyMetadata::class)]
final class MediaBodyMetadataTest extends TestCase
{
    public function testTheClassIsInternalFinalAndReadonly(): void
    {
        $class = new ReflectionClass(MediaBodyMetadata::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testEachKindCarriesItsCodecFieldsOrFiles(): void
    {
        $json = new MediaBodyMetadata(self::mediaType('application/json'), MediaBodyShapeKind::Json, new StringCodec());
        $form = new MediaBodyMetadata(
            self::mediaType('multipart/form-data'),
            MediaBodyShapeKind::Form,
            null,
            [new FormFieldMetadata('caption', new StringCodec(), true)],
            ['photo' => true],
        );
        $bytes = new MediaBodyMetadata(self::mediaType('image/png'), MediaBodyShapeKind::Bytes);

        self::assertInstanceOf(StringCodec::class, $json->codec);
        self::assertSame('caption', $form->fields[0]->name);
        self::assertSame(['photo' => true], $form->files);
        self::assertSame(MediaBodyShapeKind::Bytes, $bytes->kind);
    }

    /**
     * @param list<FormFieldMetadata> $fields
     * @param array<string, bool>     $files
     */
    #[DataProvider('refusedBodies')]
    public function testABodyIsRefusedWhereItsPartsDoNotFitItsKind(
        string $mediaType,
        MediaBodyShapeKind $kind,
        bool $codec,
        array $fields,
        array $files,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new MediaBodyMetadata(self::mediaType($mediaType), $kind, $codec ? new StringCodec() : null, $fields, $files);
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     MediaBodyShapeKind,
     *     bool,
     *     list<FormFieldMetadata>,
     *     array<string, bool>,
     *     string,
     * }>
     */
    public static function refusedBodies(): iterable
    {
        $caption = new FormFieldMetadata('caption', new StringCodec(), false);

        yield 'JSON of no JSON media type' => [
            'text/plain',
            MediaBodyShapeKind::Json,
            true,
            [],
            [],
            'a body of "text/plain" is refused: a JSON body is read in a JSON media type',
        ];
        yield 'JSON without a codec' => [
            'application/json',
            MediaBodyShapeKind::Json,
            false,
            [],
            [],
            'a body of "application/json" is refused: a JSON body is read by a codec',
        ];
        yield 'a form of no form media type' => [
            'application/json',
            MediaBodyShapeKind::Form,
            false,
            [],
            [],
            'a body of "application/json" is refused: a form is read in application/x-www-form-urlencoded or'
            . ' multipart/form-data',
        ];
        yield 'files of an urlencoded form' => [
            'application/x-www-form-urlencoded',
            MediaBodyShapeKind::Form,
            false,
            [],
            ['photo' => true],
            'a body of "application/x-www-form-urlencoded" is refused: a form carries files in multipart/form-data'
            . ' alone',
        ];
        yield 'a codec of bytes' => [
            'image/png',
            MediaBodyShapeKind::Bytes,
            true,
            [],
            [],
            'a body of "image/png" is refused: a codec reads a JSON body alone',
        ];
        yield 'fields of bytes' => [
            'image/png',
            MediaBodyShapeKind::Bytes,
            false,
            [$caption],
            [],
            'a body of "image/png" is refused: fields and files belong to a form',
        ];
        yield 'the JSON:API media type' => [
            'application/vnd.api+json',
            MediaBodyShapeKind::Json,
            true,
            [],
            [],
            'a body names the JSON:API media type',
        ];
        yield 'a name of a field and a file' => [
            'multipart/form-data',
            MediaBodyShapeKind::Form,
            false,
            [new FormFieldMetadata('photo', new StringCodec(), false)],
            ['photo' => true],
            'a form declares the name "photo" twice, and each field or file is declared once',
        ];
    }

    private static function mediaType(string $text): MediaType
    {
        return MediaType::parse($text) ?? throw new InvalidArgumentException($text);
    }
}
