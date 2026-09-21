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
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Store\RelatedResourceNotFoundException;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PortSignatureReader;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(RelatedResourceNotFoundException::class)]
final class RelatedResourceNotFoundExceptionTest extends TestCase
{
    /**
     * a `RuntimeException`, not a class of the Error module, which Store may not depend on
     */
    public function testTheClassIsAFinalRuntimeException(): void
    {
        $class = new ReflectionClass(RelatedResourceNotFoundException::class);

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
            (string) (new ReflectionClass(RelatedResourceNotFoundException::class))->getDocComment(),
        );
    }

    /**
     * the parameter names are public API
     */
    public function testTheConstructorTakesTheMissingIdentifiersAndThePrevious(): void
    {
        $class = new ReflectionClass(RelatedResourceNotFoundException::class);
        $property = $class->getProperty('missing');

        self::assertSame(
            [['missing', 'array', false], ['previous', '?' . Throwable::class, true]],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [
                        $parameter->getName(),
                        (string) $parameter->getType(),
                        $parameter->isOptional(),
                    ];
                },
                $class->getConstructor()?->getParameters() ?? [],
            ),
        );
        self::assertNull($class->getConstructor()?->getParameters()[1]?->getDefaultValue());
        self::assertTrue($property->isPublic());
        self::assertTrue($property->isReadOnly());
        self::assertSame('array', (string) $property->getType());
    }

    public function testTheMapIsKeyedByRelationshipAndHoldsNonEmptyLists(): void
    {
        $identifier = PortSignatureReader::shortName(Identifier::class);

        self::assertSame(
            ['non-empty-array<array-key, non-empty-list<' . $identifier . '>>'],
            PortSignatureReader::tags(
                new ReflectionProperty(RelatedResourceNotFoundException::class, 'missing'),
                'var',
                true,
            ),
        );
        self::assertStringStartsWith(
            'array<array-key, list<' . $identifier . '>> $missing ',
            PortSignatureReader::tags(
                new ReflectionMethod(RelatedResourceNotFoundException::class, '__construct'),
                'param',
                true,
            )[0]
                ?? '',
        );
    }

    public function testCarriesTheMissingIdentifiersByRelationshipAndThePreviousThrowable(): void
    {
        $owner = new Identifier('customers', '9');
        $first = new Identifier('tags', '2');
        $second = new Identifier('tags', '3');
        $previous = new RuntimeException('no such tag');

        $exception = new RelatedResourceNotFoundException(
            missing: ['owner' => [$owner], 'tags' => [$first, $second]],
            previous: $previous,
        );

        self::assertSame(['owner' => [$owner], 'tags' => [$first, $second]], $exception->missing);
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(0, $exception->getCode());
    }

    public function testKeepsARelationshipNamedWithDigits(): void
    {
        $identifier = new Identifier('customers', '9');

        $exception = new RelatedResourceNotFoundException(['0' => [$identifier]]);

        self::assertSame([0 => [$identifier]], $exception->missing);
        self::assertNull($exception->getPrevious());
    }

    /**
     * @param array<array-key, list<Identifier>> $missing
     */
    #[DataProvider('messages')]
    public function testTheMessageNamesEveryMissingIdentifier(array $missing, string $message): void
    {
        self::assertSame($message, (new RelatedResourceNotFoundException($missing))->getMessage());
    }

    /**
     * @return iterable<string, array{array<array-key, list<Identifier>>, string}>
     */
    public static function messages(): iterable
    {
        yield 'one relationship' => [
            ['owner' => [new Identifier('customers', '9')]],
            'the write references related resources that do not exist or are not visible: relationship "owner":'
            . ' customers "9"',
        ];
        yield 'two relationships' => [
            [
                'owner' => [new Identifier('customers', '9')],
                'tags' => [new Identifier('tags', '2'), new Identifier('tags', '3')],
            ],
            'the write references related resources that do not exist or are not visible: relationship "owner":'
            . ' customers "9"; relationship "tags": tags "2", tags "3"',
        ];
        yield 'control characters' => [
            ["ow\nner" => [new Identifier("cust\tomers", "9\r")]],
            'the write references related resources that do not exist or are not visible: relationship "ow\nner":'
            . ' cust\tomers "9\r"',
        ];
    }

    public function testRefusesAnEmptyMap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('related resources are missing from one or more relationships, and none is'
            . ' named');

        new RelatedResourceNotFoundException([]);
    }

    public function testRefusesAnEmptyRelationshipName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('related resources are missing from a relationship whose name is never empty,'
            . ' and the name is empty');

        new RelatedResourceNotFoundException([
            'owner' => [new Identifier('customers', '9')],
            '' => [new Identifier('tags', '2')],
        ]);
    }

    /**
     * @param array<array-key, list<Identifier>> $missing
     */
    #[DataProvider('mapsWithAnEmptyList')]
    public function testRefusesARelationshipWithoutAMissingIdentifier(array $missing, string $relationship): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'relationship "%s" is named with no missing identifier, and it names one or more',
            $relationship,
        ));

        new RelatedResourceNotFoundException($missing);
    }

    /**
     * @return iterable<string, array{array<array-key, list<Identifier>>, string}>
     */
    public static function mapsWithAnEmptyList(): iterable
    {
        yield 'the only relationship' => [['tags' => []], 'tags'];
        yield 'after another relationship' => [['owner' => [new Identifier('customers', '9')], 'tags' => []], 'tags'];
        yield 'a relationship named with digits' => [['7' => []], '7'];
        yield 'a relationship with a control character' => [["ta\ngs" => []], 'ta\ngs'];
    }
}
