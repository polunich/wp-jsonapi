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

namespace Polunich\WpJsonApi\Tests\Unit\Execution\Result;

use Closure;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Metadata\IdMetadata;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(ResourceRecord::class)]
final class ResourceRecordTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(ResourceRecord::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    public function testCarriesTheTypeTheIdAndTheRecord(): void
    {
        $pet = new Pet('1', 'Rex');

        $resource = new ResourceRecord('pets', '1', $pet);

        self::assertSame('pets', $resource->type);
        self::assertSame('1', $resource->id);
        self::assertSame($pet, $resource->record);
    }

    public function testCarriesNoRecordForAnIdentifierTheLibraryKnowsWithoutOne(): void
    {
        $resource = new ResourceRecord('customers', '9', null);

        self::assertNull($resource->record);
    }

    public function testPairsARecordWithTheTypeAndTheIdOfItsDeclaration(): void
    {
        $pet = new Pet('1', 'Rex');

        $resource = ResourceRecord::of(Samples::resource('pets'), $pet);

        self::assertSame('pets', $resource->type);
        self::assertSame('1', $resource->id);
        self::assertSame($pet, $resource->record);
    }

    public function testReadsTheIdThroughTheAccessorOfTheDeclaration(): void
    {
        $resource = ResourceRecord::of(
            self::withAccessor(static function (Pet $pet): string {
                return 'pet-' . $pet->id;
            }),
            new Pet('1', 'Rex'),
        );

        self::assertSame('pet-1', $resource->id);
    }

    public function testRefusesARecordWhoseIdAccessorReturnsNoString(): void
    {
        $resource = self::withAccessor(static function (Pet $pet): int {
            return 7;
        });

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('the id accessor returned int for a record of class');

        ResourceRecord::of($resource, new Pet('1', 'Rex'));
    }

    public function testRefusesARecordWhoseIdAccessorReturnsTheEmptyString(): void
    {
        $resource = self::withAccessor(static function (Pet $pet): string {
            return '';
        });

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('the id accessor returned the empty string');

        ResourceRecord::of($resource, new Pet('1', 'Rex'));
    }

    public function testRefusesAnEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a resource of a document names its type, and a type is never empty');

        new ResourceRecord('', '1', new Pet('1', 'Rex'));
    }

    #[DataProvider('typesOfAnEmptyId')]
    public function testRefusesAnEmptyId(string $type, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ResourceRecord($type, '', new Pet('1', 'Rex'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typesOfAnEmptyId(): iterable
    {
        yield 'a member name' => [
            'pets',
            'a resource of type "pets" carries an empty id, which identifies no resource [spec]',
        ];
        yield 'a control character' => ["pe\tts", 'a resource of type "pe\tts" carries an empty id'];
    }

    public function testReturnsTheRecordItCarries(): void
    {
        $pet = new Pet('1', 'Rex');

        self::assertSame($pet, (new ResourceRecord('pets', '1', $pet))->requireRecord());
    }

    public function testRefusesToReturnTheRecordOfAnIdentifierThatCarriesNone(): void
    {
        $resource = new ResourceRecord('customers', '9', null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the library holds no record of type "customers" and id "9"');

        $resource->requireRecord();
    }

    private static function withAccessor(Closure $accessor): ResourceMetadata
    {
        return Samples::resource('pets', id: new IdMetadata(new DigitsCodec(), $accessor, false));
    }
}
