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
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\CodeStyle\QualifiedNames;
use ReflectionClass;

/**
 * @extends RuleTestCase<QualifiedGlobalNameRule>
 *
 * @internal
 */
#[CoversClass(QualifiedGlobalNameRule::class)]
final class QualifiedGlobalNameRuleTest extends RuleTestCase
{
    private const GLOBAL_FIXTURE = __DIR__ . '/../Fixtures/Architecture/CodeStyle/global-names.php';

    #[Override]
    protected function getRule(): Rule
    {
        return new QualifiedGlobalNameRule(
            self::getContainer()->getByType(Lexer::class),
            self::getContainer()->getByType(PhpDocParser::class),
        );
    }

    /**
     * @param list<array{string, int}> $expected message and line of each error
     */
    #[DataProvider('fixtures')]
    public function testReportsWhatTheFixtureWrites(string $file, array $expected): void
    {
        $this->analyse([$file], $expected);
        self::assertSame(
            array_fill(0, count($expected), 'codeStyle.qualifiedGlobalName'),
            array_map(static function (Error $error): ?string {
                return $error->getIdentifier();
            }, $this->gatherAnalyserErrors([$file])),
        );
    }

    /**
     * @return array<string, array{string, list<array{string, int}>}>
     */
    public static function fixtures(): array
    {
        $namespaced = static function (string $class, int $line): array {
            return [
                sprintf(
                    'class %1$s of the global namespace is written \\%1$s; import it with use %1$s; and write %1$s',
                    $class,
                ),
                $line,
            ];
        };
        $global = static function (string $class, int $line): array {
            return [
                sprintf('class %1$s is written \\%1$s in the global namespace, where %1$s names it', $class),
                $line,
            ];
        };

        return [
            'a file in a namespace' => [(string) (new ReflectionClass(QualifiedNames::class))->getFileName(), [
                $namespaced('Throwable', 36),
                $namespaced('IteratorAggregate', 38),
                $namespaced('DateTimeInterface', 38),
                $namespaced('AllowDynamicProperties', 42),
                $namespaced('IteratorAggregate', 43),
                $namespaced('DateTimeInterface', 45),
                $namespaced('DateTimeInterface', 49),
                $namespaced('RuntimeException', 53),
                $namespaced('DateTimeInterface', 55),
                $namespaced('DateTimeInterface', 57),
                $namespaced('DateTimeImmutable', 60),
                $namespaced('Exception', 61),
                $namespaced('RuntimeException', 62),
                $namespaced('DateTimeImmutable', 69),
                $namespaced('stdClass', 72),
                $namespaced('ArrayIterator', 77),
                $namespaced('DateTimeInterface', 77),
                $namespaced('Override', 79),
                $namespaced('ArrayIterator', 80),
                $namespaced('ArrayIterator', 82),
            ]],
            'a file of the global namespace' => [self::GLOBAL_FIXTURE, [
                $global('ArrayObject', 28),
                $global('ArrayObject', 29),
            ]],
        ];
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
            '/^    -\n        class: ' . preg_quote(QualifiedGlobalNameRule::class, '/')
                . '\n        tags:\n            - phpstan\.rules\.rule$/m',
            $configuration,
        );
    }
}
