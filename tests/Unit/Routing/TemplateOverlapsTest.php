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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Routing\PathTemplate;
use Polunich\WpJsonApi\Routing\TemplateOverlaps;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\DescribedCodec;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(TemplateOverlaps::class)]
final class TemplateOverlapsTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(TemplateOverlaps::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    /**
     * @param list<array{list<string>, PathTemplate}> $routes
     * @param ?array{int, int}                        $first
     */
    #[DataProvider('routes')]
    public function testTheFirstPairOfOneMethodWhoseTemplatesOverlapIsFound(
        array $routes,
        bool $ignoringCase,
        ?array $first,
    ): void {
        self::assertSame($first, TemplateOverlaps::first($routes, $ignoringCase));
    }

    /**
     * @return iterable<string, array{
     *     list<array{list<string>, PathTemplate}>,
     *     bool,
     *     ?array{int, int},
     * }>
     */
    public static function routes(): iterable
    {
        $pets = new PathTemplate(['pets']);
        $pet = new PathTemplate(['pets', self::digits('id')]);

        yield 'no route' => [[], false, null];
        yield 'one template and one method' => [[[['GET'], $pet], [['GET'], $pet]], false, [0, 1]];
        yield 'one template and other methods' => [[[['GET'], $pet], [['PATCH'], $pet]], false, null];
        yield 'one method among others' => [[[['GET', 'HEAD'], $pet], [['DELETE', 'HEAD'], $pet]], false, [0, 1]];
        yield 'other lengths' => [[[['GET'], $pets], [['GET'], $pet]], false, null];
        yield 'a literal the codec admits' => [
            [[['GET'], $pet], [['GET'], new PathTemplate(['pets', '7'])]],
            false,
            [0, 1],
        ];
        yield 'a literal before the parameter that admits it' => [
            [[['GET'], new PathTemplate(['pets', '7'])], [['GET'], $pet]],
            false,
            [0, 1],
        ];
        yield 'a literal the codec refuses' => [
            [[['GET'], $pet], [['GET'], new PathTemplate(['pets', 'recent'])]],
            false,
            null,
        ];
        yield 'two parameters of other codecs' => [
            [[['GET'], $pet], [['GET'], new PathTemplate(['pets', self::letters('slug')])]],
            false,
            [0, 1],
        ];
        yield 'a parameter where the other has a literal of its own' => [
            [
                [['GET'], new PathTemplate(['pets', 'recent', 'photos'])],
                [['GET'], new PathTemplate([self::letters('kind'), 'recent', 'photos'])],
            ],
            false,
            [0, 1],
        ];
        yield 'literals in other case' => [[[['GET'], $pets], [['GET'], new PathTemplate(['Pets'])]], false, null];
        yield 'literals in other case, ignoring case' => [
            [[['GET'], $pets], [['GET'], new PathTemplate(['Pets'])]],
            true,
            [0, 1],
        ];
        yield 'the least earlier route first' => [
            [[['GET'], $pets], [['POST'], $pet], [['POST'], $pet], [['GET'], $pets]],
            false,
            [0, 3],
        ];
        yield 'then the least later route' => [
            [[['GET'], $pet], [['GET'], $pets], [['GET'], $pet], [['GET'], $pet]],
            false,
            [0, 2],
        ];
    }

    /**
     * the tree only narrows the pairs, so it returns what a loop over every pair returns:
     * templates of two lengths drawn from literals in either case, a literal a codec admits,
     * and parameters of two codecs, each with one or two methods
     */
    public function testTheResultIsTheResultOfEveryPair(): void
    {
        $randomizer = new Randomizer(new Mt19937(20260925));
        $methods = [['GET'], ['POST'], ['GET', 'HEAD'], ['HEAD']];

        for ($declaration = 0; $declaration < 300; ++$declaration) {
            $routes = [];

            for ($route = $randomizer->getInt(2, 12); $route > 0; --$route) {
                $template = [self::segment($randomizer, 1)];

                if ($randomizer->getInt(0, 1) === 1) {
                    $template[] = self::segment($randomizer, 2);
                }

                $routes[] = [$methods[$randomizer->getInt(0, count($methods) - 1)], new PathTemplate($template)];
            }

            foreach ([false, true] as $ignoringCase) {
                self::assertSame(
                    self::everyPair($routes, $ignoringCase),
                    TemplateOverlaps::first($routes, $ignoringCase),
                    sprintf('declaration %d, ignoring case: %s', $declaration, $ignoringCase ? 'yes' : 'no'),
                );
            }
        }
    }

    /**
     * @param list<array{list<string>, PathTemplate}> $routes
     *
     * @return ?array{int, int}
     */
    private static function everyPair(array $routes, bool $ignoringCase): ?array
    {
        foreach ($routes as $earlier => [$methods, $template]) {
            foreach ($routes as $later => [$laterMethods, $laterTemplate]) {
                if (
                    $later > $earlier
                    && array_intersect($methods, $laterMethods) !== []
                    && $template->overlaps($laterTemplate, $ignoringCase)
                ) {
                    return [$earlier, $later];
                }
            }
        }

        return null;
    }

    /**
     * a parameter is named after its position, since a template names each parameter once
     */
    private static function segment(Randomizer $randomizer, int $position): string|PathParameter
    {
        return match ($randomizer->getInt(0, 5)) {
            0 => 'pets',
            1 => 'Pets',
            2 => 'photos',
            3 => '7',
            4 => self::digits('p' . $position),
            default => self::letters('p' . $position),
        };
    }

    private static function digits(string $name): PathParameter
    {
        return new PathParameter($name, new DigitsCodec());
    }

    private static function letters(string $name): PathParameter
    {
        return new PathParameter($name, new DescribedCodec(new Schema(type: JsonType::String), '[a-z]+'));
    }
}
