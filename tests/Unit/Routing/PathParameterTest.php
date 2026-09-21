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

namespace Polunich\WpJsonApi\Tests\Unit\Routing;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Routing\PathParameter;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(PathParameter::class)]
final class PathParameterTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(PathParameter::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheParameterCarriesItsNameAndItsCodec(): void
    {
        $codec = new DigitsCodec();
        $parameter = new PathParameter('pet_id', $codec);

        self::assertSame('pet_id', $parameter->name);
        self::assertSame($codec, $parameter->codec);
    }

    #[DataProvider('names')]
    public function testANameOfAGroupEveryPhpCompilesIsAccepted(string $name): void
    {
        self::assertSame($name, (new PathParameter($name, new DigitsCodec()))->name);
        self::assertSame(1, preg_match('@(?P<' . $name . '>x)@', 'x'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function names(): iterable
    {
        yield 'the default' => ['id'];
        yield 'with an underscore' => ['pet_id'];
        yield 'an underscore first' => ['_id'];
        yield 'a digit after the first' => ['a1'];
        yield 'upper case' => ['PetId'];
        yield 'one letter' => ['p'];
        yield 'thirty-two characters' => [str_repeat('a', 32)];
    }

    #[DataProvider('namesNoGroupTakes')]
    public function testANameThatIsNoGroupNameOnEveryPhpIsRefused(string $name, string $written): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'a path parameter is named "%s", and a path parameter is named by a letter or "_" and at most 31 more'
            . ' letters, digits or "_": WordPress hands it over as a named group of its route pattern, and PCRE2'
            . ' before 10.44, which PHP 8.3 carries, compiles a group name of at most 32 characters',
            $written,
        ));

        new PathParameter($name, new DigitsCodec());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function namesNoGroupTakes(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'a digit first' => ['1id', '1id'];
        yield 'a hyphen' => ['pet-id', 'pet-id'];
        yield 'a space' => ['pet id', 'pet id'];
        yield 'a letter above U+007F' => ["\u{e9}", "\u{e9}"];
        yield 'thirty-three characters' => [str_repeat('a', 33), str_repeat('a', 33)];
        yield 'a line feed at the end' => ["id\n", 'id\n'];
    }

    public function testTheMessageNamesWhatCarriesTheName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the id parameter of type "pets" is named "pet-id", and a path parameter');

        PathParameter::checkName('pet-id', 'the id parameter of type "pets"');
    }
}
