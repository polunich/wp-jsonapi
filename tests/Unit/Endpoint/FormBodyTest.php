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
use Polunich\WpJsonApi\Endpoint\FormBody;
use Polunich\WpJsonApi\Http\UploadedFile;
use Polunich\WpJsonApi\Operation\Values;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(FormBody::class)]
final class FormBodyTest extends TestCase
{
    public function testTheValueIsPublicFinalAndReadonly(): void
    {
        $class = new ReflectionClass(FormBody::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertDoesNotMatchRegularExpression('/^\s*\*\s*@internal\b/m', (string) $class->getDocComment());
    }

    public function testAFormTellsTheFilesItCarries(): void
    {
        $photo = new UploadedFile('rex.jpg', 'image/jpeg', 2048, '/tmp/phpA', UPLOAD_ERR_OK);
        $form = new FormBody(new Values(['caption' => 'Rex']), ['photo' => $photo]);

        self::assertSame('Rex', $form->fields->get('caption'));
        self::assertTrue($form->hasFile('photo'));
        self::assertFalse($form->hasFile('thumbnail'));
        self::assertSame($photo, $form->file('photo'));
    }

    public function testAFileTheFormLacksIsNoneToRead(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('the form carries no file "thumbnail"');

        (new FormBody(new Values([]), []))->file('thumbnail');
    }
}
