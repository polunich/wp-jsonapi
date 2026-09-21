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

namespace Polunich\WpJsonApi\Tests\Architecture;

use Override;
use PHPStan\Analyser\Error;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\CodeStyle\ArrowFunctions;
use ReflectionClass;

/**
 * @extends RuleTestCase<ArrowFunctionRule>
 *
 * @internal
 */
#[CoversClass(ArrowFunctionRule::class)]
final class ArrowFunctionRuleTest extends RuleTestCase
{
    private const MESSAGE = 'an arrow function is written; write the closure in full, function with the variables it'
        . ' captures in use';

    #[Override]
    protected function getRule(): Rule
    {
        return new ArrowFunctionRule();
    }

    public function testReportsEveryArrowFunction(): void
    {
        $file = (string) (new ReflectionClass(ArrowFunctions::class))->getFileName();

        $this->analyse([$file], [
            [self::MESSAGE, 42],
            [self::MESSAGE, 43],
            [self::MESSAGE, 44],
            [self::MESSAGE, 44],
        ]);
        self::assertSame(
            array_fill(0, 4, 'codeStyle.arrowFunction'),
            array_map(static function (Error $error): ?string {
                return $error->getIdentifier();
            }, $this->gatherAnalyserErrors([$file])),
        );
    }

    public function testTakesTheNodeOfAFile(): void
    {
        self::assertSame(FileNode::class, $this->getRule()->getNodeType());
    }

    public function testThePhpStanConfigurationRegistersTheRule(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 2) . '/phpstan.neon.dist');

        self::assertIsString($configuration);
        self::assertMatchesRegularExpression(
            '/^    -\n        class: ' . preg_quote(ArrowFunctionRule::class, '/')
                . '\n        tags:\n            - phpstan\.rules\.rule$/m',
            $configuration,
        );
    }
}
