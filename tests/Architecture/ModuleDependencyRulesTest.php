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

use FilesystemIterator;
use PHPat\Rule\Assertion\Constraint;
use PHPat\Selector\Classname;
use PHPat\Selector\IsEnum;
use PHPat\Selector\IsInterface;
use PHPat\Selector\IsThrowable;
use PHPat\Selector\IsTrait;
use PHPat\Selector\SelectorInterface;
use PHPat\Statement\Statement;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Polunich\WpJsonApi\Declaration\AtomicRoute;
use Polunich\WpJsonApi\Declaration\Attribute;
use Polunich\WpJsonApi\Declaration\BodyShape;
use Polunich\WpJsonApi\Declaration\Endpoint;
use Polunich\WpJsonApi\Declaration\Relationship;
use Polunich\WpJsonApi\Declaration\ResourceType;
use Polunich\WpJsonApi\Declaration\ToMany;
use Polunich\WpJsonApi\Declaration\ToOne;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Execution\Atomic\LocalIds;
use Polunich\WpJsonApi\Execution\IncludeWalk;
use Polunich\WpJsonApi\Http\Content;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;

/**
 * the rules are read the way PHPStan reads them,
 * as PHPat statements built from the classes `phpstan.neon.dist` tags `phpat.test`:
 * a rule taken off the methods directly would still pass with its registration gone
 *
 * @internal
 */
#[CoversClass(ModuleDependencyRules::class)]
final class ModuleDependencyRulesTest extends PHPStanTestCase
{
    private const ROOT = 'Polunich\WpJsonApi\\';

    /**
     * the classes each module keeps mutable on purpose, as `ModuleDependencyRules` names them
     *
     * @var array<string, list<class-string>>
     */
    private const MUTABLE = [
        'Http' => [Content::class],
        'Declaration' => [
            ResourceType::class,
            Endpoint::class,
            AtomicRoute::class,
            Attribute::class,
            Relationship::class,
            ToOne::class,
            ToMany::class,
            BodyShape::class,
        ],
        'Error' => [ErrorList::class],
        'Execution' => [LocalIds::class, IncludeWalk::class],
    ];

    /**
     * PHPat names a yielded rule after its method and its yield key with the separators dropped,
     * in the declaration order of the methods
     */
    public function testTheReadonlyRulesNameTheValueModulesAndTheServiceModules(): void
    {
        $values = [
            'Text',
            'Codec',
            'Http',
            'Routing',
            'Localization',
            'Document',
            'Authentication',
            'Query',
            'Operation',
            'Metadata',
            'Store',
            'Endpoint',
            'Declaration',
        ];
        $services = ['Error', 'Parsing', 'Access', 'Execution', 'Rendering', 'OpenApi', 'Server'];

        self::assertSame(
            [
                ...array_map(static function (string $module): string {
                    return 'valueModulesAreReadonly' . $module;
                }, $values),
                ...array_map(static function (string $module): string {
                    return 'serviceModulesAreReadonly' . $module;
                }, $services),
            ],
            array_keys(self::readonlyStatements()),
        );
        self::assertSame(
            array_map(static function (string $module): string {
                return self::ROOT . $module;
            }, $services),
            array_map(
                static function (Statement $statement): string {
                    return $statement->subject->getName();
                },
                array_values(self::serviceStatements()),
            ),
        );

        foreach (self::readonlyStatements() as $name => $statement) {
            self::assertSame(Constraint::Should, $statement->constraint, $name . ' is no rule should be readonly');
        }
    }

    public function testTheReadonlyRulesExcludeWhatCarriesNoModifierAndTheMutableClasses(): void
    {
        foreach (self::readonlyStatements() as $name => $statement) {
            $excludes = array_values($statement->subjectExcludes);
            $module = substr($statement->subject->getName(), strlen(self::ROOT));
            $mutable = self::MUTABLE[$module] ?? [];

            self::assertSame(
                [
                    IsInterface::class,
                    IsEnum::class,
                    IsTrait::class,
                    IsThrowable::class,
                    ...array_fill(0, count($mutable), Classname::class),
                ],
                array_map(static function (SelectorInterface $selector): string {
                    return $selector::class;
                }, $excludes),
                $name . ' excludes another set',
            );
            self::assertSame(
                $mutable,
                array_map(static function (SelectorInterface $selector): string {
                    return $selector->getName();
                }, array_slice($excludes, 4)),
                $name . ' names other classes',
            );
        }
    }

    /**
     * PHPat reports nothing for a class an exclusion swallows,
     * so an exclusion wide enough to hide a real service would keep the analysis green;
     * the selectors are therefore applied to the classes of each module on disk
     */
    public function testEveryReadonlyRuleLeavesOutOnlyTheClassesThatCannotBeReadonlyAndTheMutableOnes(): void
    {
        $mutable = array_merge(...array_values(self::MUTABLE));
        $left = [];

        foreach (self::readonlyStatements() as $statement) {
            foreach (self::classesOf($statement->subject->getName()) as $class) {
                if (!$statement->subject->matches($class)) {
                    continue;
                }

                $excluded = false;
                foreach ($statement->subjectExcludes as $exclude) {
                    $excluded = $excluded || $exclude->matches($class);
                }

                if (!$excluded) {
                    self::assertTrue($class->isReadOnly(), $class->getName() . ' is no readonly class');

                    continue;
                }

                $left[] = $class->getName();
                $native = $class->getNativeReflection();

                self::assertTrue(
                    $native->isInterface() || $native->isEnum() || $native->isTrait()
                    || $native->implementsInterface(Throwable::class) || in_array($class->getName(), $mutable, true),
                    $class->getName() . ' is left out, and it could be readonly',
                );
            }
        }

        foreach ($mutable as $class) {
            self::assertContains($class, $left, $class . ' is named mutable and no rule leaves it out');
            self::assertFalse(
                (new ReflectionClass($class))->isReadOnly(),
                $class . ' is named mutable and is readonly',
            );
        }
    }

    /**
     * `phpstan-core.neon.dist` is the run without the WordPress stubs,
     * so it includes no other configuration;
     * a core module it does not list would be analysed only with the stubs loaded
     */
    public function testTheCoreConfigurationAnalysesExactlyTheCoreModules(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 2) . '/phpstan-core.neon.dist');

        self::assertIsString($configuration);
        self::assertMatchesRegularExpression('/^    level: 10$/m', $configuration);
        self::assertDoesNotMatchRegularExpression('/^\s*includes:/m', $configuration);

        if (preg_match('/^    paths:\n((?:        - .+\n)+)/m', $configuration, $paths) !== 1) {
            self::fail('phpstan-core.neon.dist sets no paths');
        }

        $listed = array_map(
            static function (string $line): string {
                return substr($line, strlen('        - src/'));
            },
            explode("\n", rtrim($paths[1], "\n")),
        );
        $modules = self::coreModules();
        sort($modules);

        self::assertSame($modules, $listed);
    }

    /**
     * @return list<string>
     */
    private static function coreModules(): array
    {
        $core = (new ReflectionClass(ModuleDependencyRules::class))->getConstant('CORE');

        self::assertIsArray($core);

        return array_map(static function (mixed $module): string {
            return (string) $module;
        }, array_keys($core));
    }

    /**
     * @return array<string, Statement>
     */
    private static function readonlyStatements(): array
    {
        $statements = [];
        foreach (NamingTraitRuleTest::libraryStatementBuilder()->build('beReadonly') as $statement) {
            $statements[$statement->ruleName] = $statement;
        }

        return $statements;
    }

    /**
     * @return array<string, Statement>
     */
    private static function serviceStatements(): array
    {
        return self::statementsOf('serviceModulesAreReadonly');
    }

    /**
     * @return array<string, Statement>
     */
    private static function statementsOf(string $rule): array
    {
        $statements = array_filter(
            self::readonlyStatements(),
            static function (string $name) use ($rule): bool {
                return str_starts_with($name, $rule);
            },
            ARRAY_FILTER_USE_KEY,
        );
        self::assertNotSame([], $statements, 'no statement of ' . $rule);

        return $statements;
    }

    /**
     * the classes of a module as PHPStan reflections, the only thing a PHPat selector matches;
     * the directory follows the namespace by the PSR-4 map of `composer.json`
     *
     * @return list<ClassReflection>
     */
    private static function classesOf(string $namespace): array
    {
        $directory = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($namespace, strlen(self::ROOT)));

        if (!is_dir($directory)) {
            return [];
        }

        $paths = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);
        $provider = self::createReflectionProvider();
        $classes = [];

        foreach ($paths as $path) {
            $name = $namespace . '\\' . str_replace('/', '\\', substr($path, strlen($directory) + 1, -4));
            self::assertTrue($provider->hasClass($name), 'no class ' . $name . ' for ' . $path);
            $classes[] = $provider->getClass($name);
        }

        return $classes;
    }
}
