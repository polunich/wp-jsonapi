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
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Violation;
use Polunich\WpJsonApi\Error\LibraryErrorCatalog;
use Polunich\WpJsonApi\Parsing\ViolationMapper;

/**
 * @internal
 */
#[CoversClass(ViolationMapper::class)]
final class ViolationMapperTest extends TestCase
{
    public function testDescribesEveryViolationOfADecodeAsItsOwnProblem(): void
    {
        $exception = new DecodeException([
            new Violation(
                Pointer::root()->descend('data')->descend('attributes')->descend('width'),
                'invalid_type',
                ['expected' => 'integer'],
            ),
            new Violation(
                Pointer::root()->descend('data')->descend('attributes')->descend('height'),
                'value_too_small',
                ['minimum' => 1],
            ),
        ]);

        $descriptions = ViolationMapper::describe($exception);

        self::assertCount(2, $descriptions);
        self::assertSame('invalid_type', $descriptions[0]->code);
        self::assertSame(['expected' => 'integer'], $descriptions[0]->parameters);
        self::assertSame('/data/attributes/width', $descriptions[0]->pointer);
        self::assertSame('value_too_small', $descriptions[1]->code);
        self::assertSame(['minimum' => 1], $descriptions[1]->parameters);
        self::assertSame('/data/attributes/height', $descriptions[1]->pointer);
    }

    public function testAProblemNamesThePointerOfTheValueAndNoOtherSource(): void
    {
        $exception = new DecodeException([new Violation(Pointer::root(), 'value_rejected')]);

        $description = ViolationMapper::describe($exception)[0];

        self::assertSame('', $description->pointer);
        self::assertNull($description->parameter);
        self::assertNull($description->header);
        self::assertNull($description->status);
    }

    public function testTheCodeOfAViolationIsOneTheCatalogOfTheLibraryWords(): void
    {
        $catalog = new LibraryErrorCatalog();

        try {
            (new IntegerCodec())->decode(
                '13',
                Pointer::root()->descend('data')->descend('attributes')->descend('width'),
            );

            self::fail('the codec accepted a string where it declares an integer');
        } catch (DecodeException $exception) {
            $description = ViolationMapper::describe($exception)[0];

            self::assertSame('/data/attributes/width', $description->pointer);
            self::assertIsString(
                $description->code,
                'a violation carries a code of its own, never a case of ErrorCode',
            );
            self::assertSame('invalid type', $catalog->title($description->code));
            self::assertSame(
                'the value is not of the JSON type integer',
                $catalog->detail($description->code, $description->parameters),
            );
        }
    }
}
