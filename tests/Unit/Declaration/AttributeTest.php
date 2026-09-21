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

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\ListCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Declaration\Attribute;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Attribute::class)]
final class AttributeTest extends TestCase
{
    public function testTheClassIsFinalMutableAndPublicApi(): void
    {
        $class = new ReflectionClass(Attribute::class);

        self::assertTrue($class->isFinal());
        self::assertFalse($class->isReadOnly());
        self::assertStringNotContainsString('@internal', (string) $class->getDocComment());
        self::assertStringContainsString('@internal', (string) $class->getMethod('build')->getDocComment());
    }

    public function testEveryMethodReturnsTheBuilderItChanged(): void
    {
        $attribute = Attribute::make('name', new StringCodec());

        self::assertSame($attribute, $attribute->accessor(static function (Pet $pet): string {
            return $pet->name;
        }));
        self::assertSame($attribute, $attribute->creatable());
        self::assertSame($attribute, $attribute->updatable());
        self::assertSame($attribute, $attribute->required());
        self::assertSame($attribute, $attribute->sortable());
        self::assertSame($attribute, $attribute->filter('@eq'));
        self::assertSame($attribute, $attribute->description('the name'));
        self::assertSame($attribute, $attribute->deprecated());

        $attribute->filter('@ne');

        self::assertSame(['@eq', '@ne'], $attribute->build()->operators);
    }

    public function testCarriesWhatTheDeclarationStates(): void
    {
        $codec = new StringCodec(maxLength: 200);
        $attribute = Attribute::make('name', $codec)
            ->accessor(static function (Pet $pet): string {
                return $pet->name;
            })
            ->creatable()
            ->updatable()
            ->required()
            ->sortable()
            ->filter('@eq', '@containsi')
            ->description('the name of the pet')
            ->deprecated()
            ->build();

        self::assertSame('name', $attribute->name);
        self::assertSame($codec, $attribute->codec);
        self::assertTrue($attribute->hasAccessor());
        self::assertSame('Rex', $attribute->valueOf(new Pet('1', 'Rex')));
        self::assertSame(
            [true, true, true],
            [$attribute->writeRule->creatable, $attribute->writeRule->updatable, $attribute->writeRule->required],
        );
        self::assertTrue($attribute->sortable);
        self::assertSame(['@eq', '@containsi'], $attribute->operators);
        self::assertSame('the name of the pet', $attribute->description);
        self::assertTrue($attribute->deprecated);
    }

    public function testAnAttributeIsReadOnlyUntilTheDeclarationWritesMore(): void
    {
        $attribute = Attribute::make('name', new StringCodec())
            ->accessor(static function (Pet $pet): string {
                return $pet->name;
            })
            ->build();

        self::assertSame(
            [false, false, false],
            [$attribute->writeRule->creatable, $attribute->writeRule->updatable, $attribute->writeRule->required],
        );
        self::assertFalse($attribute->sortable);
        self::assertSame([], $attribute->operators);
        self::assertNull($attribute->description);
        self::assertFalse($attribute->deprecated);
    }

    public function testAnAttributeWithoutAccessorIsWrittenAndNeverRead(): void
    {
        $attribute = Attribute::make('password', new StringCodec())->creatable()->updatable()->build();

        self::assertFalse($attribute->hasAccessor());
    }

    public function testRefusesARequiredAttributeThatACreateMayNotSend(): void
    {
        $attribute = Attribute::make('name', new StringCodec())
            ->accessor(static function (Pet $pet): string {
                return $pet->name;
            })
            ->required();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a field required on create is creatable');

        $attribute->build();
    }

    public function testRefusesAnOperatorNamedTwiceAcrossCalls(): void
    {
        $attribute = Attribute::make('name', new StringCodec())
            ->accessor(static function (Pet $pet): string {
                return $pet->name;
            })
            ->filter('@eq')
            ->filter('@containsi', '@eq');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attribute "name" declares operator "@eq" twice');

        $attribute->build();
    }

    /**
     * @param Closure(Attribute): mixed $second
     */
    #[DataProvider('partsWrittenTwice')]
    public function testRefusesAPartOfTheDeclarationWrittenTwice(Closure $second, string $message): void
    {
        $attribute = Attribute::make('name', new StringCodec())
            ->accessor(static function (Pet $pet): string {
                return $pet->name;
            })
            ->description('the name');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $second($attribute);
    }

    /**
     * @return iterable<string, array{Closure(Attribute): mixed, string}>
     */
    public static function partsWrittenTwice(): iterable
    {
        yield 'an accessor' => [
            static function (Attribute $attribute): Attribute {
                return $attribute->accessor(static function (Pet $pet): string {
                    return $pet->id;
                });
            },
            'attribute "name" declares an accessor twice, and each part of a declaration is written once',
        ];
        yield 'a description' => [
            static function (Attribute $attribute): Attribute {
                return $attribute->description('another name');
            },
            'attribute "name" declares a description twice, and each part of a declaration is written once',
        ];
    }

    public function testAFlagWrittenTwiceSaysWhatItSaidOnce(): void
    {
        $attribute = Attribute::make('name', new StringCodec())
            ->accessor(static function (Pet $pet): string {
                return $pet->name;
            })
            ->sortable()
            ->sortable()
            ->creatable()
            ->creatable()
            ->deprecated()
            ->deprecated()
            ->build();

        self::assertSame(
            [true, true, true],
            [$attribute->sortable, $attribute->writeRule->creatable, $attribute->deprecated],
        );
    }

    /**
     * @param Closure(): Attribute $declaration
     */
    #[DataProvider('impossibleAttributes')]
    public function testRefusesADeclarationNoAttributeCanHave(Closure $declaration, string $message): void
    {
        $attribute = $declaration();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $attribute->build();
    }

    /**
     * @return iterable<string, array{Closure(): Attribute, string}>
     */
    public static function impossibleAttributes(): iterable
    {
        yield 'a name that is no member name' => [
            static function (): Attribute {
                return Attribute::make('first.name', new StringCodec())->creatable();
            },
            'attribute "first.name" is no member name',
        ];
        yield 'an @-member' => [
            static function (): Attribute {
                return Attribute::make('@context', new StringCodec())->creatable();
            },
            'attribute "@context" is an @-member',
        ];
        yield 'a name of a resource object' => [
            static function (): Attribute {
                return Attribute::make('id', new StringCodec())->creatable();
            },
            'attribute "id" shares the namespace of type and id, and no attribute or relationship is named type or'
            . ' id [spec, Fields]',
        ];
        yield 'neither read nor written' => [
            static function (): Attribute {
                return Attribute::make('secret', new StringCodec());
            },
            'attribute "secret" has no accessor and a read-only write rule',
        ];
        yield 'sortable without an accessor' => [
            static function (): Attribute {
                return Attribute::make('name', new StringCodec())->creatable()->sortable();
            },
            'attribute "name" is sortable and has no accessor',
        ];
        yield 'sortable with a codec of no scalar' => [
            static function (): Attribute {
                return Attribute::make('tags', new ListCodec(new StringCodec()))
                    ->accessor(static function (Pet $pet): array {
                        return [$pet->name];
                    })
                    ->sortable();
            },
            'attribute "tags" is sortable and its codec admits no scalar type',
        ];
    }
}
