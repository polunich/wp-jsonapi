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

namespace Polunich\WpJsonApi\Tests\Unit\Declaration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Declaration\BodyShape;
use Polunich\WpJsonApi\Metadata\Endpoint\FormFieldMetadata;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(BodyShape::class)]
final class BodyShapeTest extends TestCase
{
    public function testTheShapeIsAPublicFinalClassMadeByItsFactories(): void
    {
        $class = new ReflectionClass(BodyShape::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->getConstructor()?->isPrivate());
        self::assertDoesNotMatchRegularExpression('/^\s*\*\s*@internal\b/m', (string) $class->getDocComment());
    }

    public function testAFieldOrAFileDeclaredOnALineOfItsOwnStillCounts(): void
    {
        $form = BodyShape::form('multipart/form-data');
        $form->field('caption', new StringCodec(), required: true);
        $form->file('photo');

        $body = $form->buildMedia('the endpoint');

        self::assertSame(['caption'], array_map(static function (FormFieldMetadata $field): string {
            return $field->name;
        }, $body->fields));
        self::assertTrue($body->fields[0]->required);
        self::assertSame(['photo' => false], $body->files);
    }

    public function testAFieldOrAFileBelongsToAForm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a field belongs to a form, and this body is none');

        BodyShape::bytes('image/png')->field('caption', new StringCodec());
    }

    public function testAFileIsDeclaredOnce(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a form declares the file "photo" twice');

        BodyShape::form('multipart/form-data')->file('photo')->file('photo', required: true);
    }
}
