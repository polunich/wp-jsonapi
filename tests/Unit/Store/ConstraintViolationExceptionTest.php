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

namespace Polunich\WpJsonApi\Tests\Unit\Store;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Store\ConstraintViolationException;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PortSignatureReader;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(ConstraintViolationException::class)]
final class ConstraintViolationExceptionTest extends TestCase
{
    /**
     * a `RuntimeException`, not a class of the Error module, which Store may not depend on
     */
    public function testTheClassIsAFinalRuntimeException(): void
    {
        $class = new ReflectionClass(ConstraintViolationException::class);

        self::assertTrue($class->isFinal());
        self::assertSame(
            RuntimeException::class,
            $class->getParentClass() !== false ? $class->getParentClass()->getName() : null,
        );
    }

    public function testTheClassIsPublicApi(): void
    {
        self::assertStringNotContainsString(
            '@internal',
            (string) (new ReflectionClass(ConstraintViolationException::class))->getDocComment(),
        );
    }

    /**
     * the parameter names are public API, and so are the properties they promote
     */
    public function testTheConstructorTakesTheConstraintTheFieldsAndThePrevious(): void
    {
        $class = new ReflectionClass(ConstraintViolationException::class);

        self::assertSame(
            [
                ['constraint', 'string', false, null],
                ['fields', 'array', true, []],
                ['previous', '?' . Throwable::class, true, null],
            ],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [
                        $parameter->getName(),
                        (string) $parameter->getType(),
                        $parameter->isOptional(),
                        $parameter->isOptional() ? $parameter->getDefaultValue() : null,
                    ];
                },
                $class->getConstructor()?->getParameters() ?? [],
            ),
        );

        foreach (['constraint' => 'string', 'fields' => 'array'] as $name => $type) {
            $property = $class->getProperty($name);

            self::assertTrue($property->isPublic());
            self::assertTrue($property->isReadOnly());
            self::assertSame($type, (string) $property->getType());
        }

        self::assertSame(
            ['string', 'list<string>', '?' . Throwable::class],
            PortSignatureReader::tags(
                new ReflectionMethod(ConstraintViolationException::class, '__construct'),
                'param',
            ),
        );
    }

    public function testCarriesTheConstraintTheFieldsAndThePreviousThrowable(): void
    {
        $previous = new RuntimeException('duplicate entry for key slug');

        $exception = new ConstraintViolationException(
            constraint: 'unique_slug',
            fields: ['slug', 'owner'],
            previous: $previous,
        );

        self::assertSame('unique_slug', $exception->constraint);
        self::assertSame(['slug', 'owner'], $exception->fields);
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(0, $exception->getCode());
    }

    public function testNamesNoFieldByDefault(): void
    {
        $exception = new ConstraintViolationException('pet_in_use');

        self::assertSame([], $exception->fields);
        self::assertNull($exception->getPrevious());
    }

    /**
     * @param list<string> $fields
     */
    #[DataProvider('messages')]
    public function testTheMessageNamesTheConstraintAndItsFields(array $fields, string $message): void
    {
        self::assertSame($message, (new ConstraintViolationException('unique_slug', $fields))->getMessage());
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function messages(): iterable
    {
        yield 'no field' => [[], 'the write violates constraint "unique_slug"'];
        yield 'one field' => [['slug'], 'the write violates constraint "unique_slug" of fields "slug"'];
        yield 'two fields' => [
            ['slug', 'owner'],
            'the write violates constraint "unique_slug" of fields "slug", "owner"',
        ];
        yield 'a field with a control character' => [
            ["sl\nug"],
            'the write violates constraint "unique_slug" of fields "sl\nug"',
        ];
    }

    #[DataProvider('lowerSnakeNames')]
    public function testTakesEveryLowerSnakeConstraint(string $constraint): void
    {
        self::assertSame($constraint, (new ConstraintViolationException($constraint))->constraint);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lowerSnakeNames(): iterable
    {
        yield 'one letter' => ['a'];
        yield 'one word' => ['taken'];
        yield 'words joined by underscores' => ['unique_slug_per_owner'];
    }

    /**
     * the `D` modifier keeps `$` from matching before a trailing line feed
     */
    #[DataProvider('namesThatAreNoLowerSnake')]
    public function testRefusesAConstraintThatIsNoLowerSnakeName(string $constraint, string $written): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'constraint "%s" is no lower_snake name of lower-case letters and single underscores',
            $written,
        ));

        new ConstraintViolationException($constraint);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function namesThatAreNoLowerSnake(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'an upper-case letter' => ['Unique_slug', 'Unique_slug'];
        yield 'a digit' => ['unique_slug2', 'unique_slug2'];
        yield 'a leading underscore' => ['_unique', '_unique'];
        yield 'a trailing underscore' => ['unique_', 'unique_'];
        yield 'two underscores' => ['unique__slug', 'unique__slug'];
        yield 'a hyphen' => ['unique-slug', 'unique-slug'];
        yield 'a space' => ['unique slug', 'unique slug'];
        yield 'a trailing line feed' => ["unique\n", 'unique\n'];
    }

    /**
     * @param list<string> $fields
     */
    #[DataProvider('fieldListsWithAnEmptyField')]
    public function testRefusesAnEmptyField(array $fields): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('constraint "unique_slug" names an empty field, and a field name is never'
            . ' empty');

        new ConstraintViolationException('unique_slug', $fields);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function fieldListsWithAnEmptyField(): iterable
    {
        yield 'the only field' => [['']];
        yield 'after another field' => [['slug', '']];
    }

    /**
     * a field named twice would give the 409 two identical errors
     *
     * @param list<string> $fields
     */
    #[DataProvider('fieldListsWithARepeatedField')]
    public function testRefusesAFieldNamedTwice(array $fields, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ConstraintViolationException('unique_slug', $fields);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function fieldListsWithARepeatedField(): iterable
    {
        yield 'side by side' => [['slug', 'slug'], 'constraint "unique_slug" names field "slug" twice'];
        yield 'apart' => [['slug', 'owner', 'slug'], 'constraint "unique_slug" names field "slug" twice'];
        yield 'a name of digits' => [['1', '1'], 'constraint "unique_slug" names field "1" twice'];
        yield 'a control character' => [["a\tb", "a\tb"], 'constraint "unique_slug" names field "a\tb" twice'];
    }

    public function testTakesFieldsThatDifferAsStrings(): void
    {
        self::assertSame(
            ['1', '01', '1.0'],
            (new ConstraintViolationException('unique_slug', ['1', '01', '1.0']))->fields,
        );
    }
}
