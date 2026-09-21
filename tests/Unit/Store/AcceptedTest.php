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
use Polunich\WpJsonApi\Store\Accepted;
use Polunich\WpJsonApi\Tests\Fixtures\Store\Job;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;

/**
 * @internal
 */
#[CoversClass(Accepted::class)]
final class AcceptedTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(Accepted::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    public function testTheClassIsPublicApi(): void
    {
        self::assertStringNotContainsString(
            '@internal',
            (string) (new ReflectionClass(Accepted::class))->getDocComment(),
        );
    }

    /**
     * the parameter names are public API, and so are the properties they promote
     */
    public function testTheConstructorPromotesTheTypeAndTheRecord(): void
    {
        $class = new ReflectionClass(Accepted::class);

        self::assertSame(
            [['type', 'string'], ['record', 'object']],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getConstructor()?->getParameters() ?? [],
            ),
        );
        self::assertSame(
            [['type', 'string', true], ['record', 'object', true]],
            array_map(
                static function (ReflectionProperty $property): array {
                    return [
                        $property->getName(),
                        (string) $property->getType(),
                        $property->isPublic(),
                    ];
                },
                $class->getProperties(),
            ),
        );
    }

    public function testCarriesTheMonitorTypeAndItsRecord(): void
    {
        $job = new Job('7');

        $accepted = new Accepted(type: 'jobs', record: $job);

        self::assertSame('jobs', $accepted->type);
        self::assertSame($job, $accepted->record);
    }

    #[DataProvider('types')]
    public function testTakesEveryTypeThatIsNotEmpty(string $type): void
    {
        self::assertSame($type, (new Accepted($type, new Job('7')))->type);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function types(): iterable
    {
        yield 'a member name' => ['jobs'];
        yield 'zero' => ['0'];
        yield 'a space' => [' '];
        yield 'a reserved character of a member name' => ['jo.bs'];
    }

    public function testRefusesAnEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an accepted write names the type of the resource that monitors it, and a type'
            . ' is never empty');

        new Accepted('', new Job('7'));
    }
}
