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

use InvalidArgumentException;
use Override;
use PHPat\Configuration;
use PHPat\Rule\Assertion\Constraint;
use PHPat\Selector\Selector;
use PHPat\Statement\Statement;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Analyser\Error;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPStan\Type\FileTypeMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Allowed\Withheld;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Core\Excused;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Core\NamesAllowed;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Core\NamesDriver;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Core\NamesForbidden;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Core\NamesPlatform;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Core\UnusedNamesAllowed;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Core\UsesNamesAllowed;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Core\UsesNamesForbidden;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Core\UsesNamesPlatform;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Core\WritesEveryKind;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Driver\DriverValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Elsewhere\AlsoUsesNamesAllowed;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Elsewhere\AlsoUsesNamesForbidden;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Elsewhere\Unselected;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\Borrowed;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\FailureException;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\ForbiddenValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\IntersectionValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\Marker;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\MethodTagParameterValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\MethodTagValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\MixinValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\ParamTagValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\PropertyReadTagValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\PropertyTagValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\PropertyWriteTagValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\ReturnTagValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\ReturnValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\TemplateBoundValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\UnionValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Platform\WpDatabase;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Platform\WpPost;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * the fixture modules under `Fixtures/Architecture/Modules` get statements of their own,
 * `fixtureStatements()`, in the shape of `ModuleDependencyRules`:
 * `Core` may depend on itself and on `Allowed` but not on `Withheld`,
 * must not name a `Platform` class a regular expression matches, as the WordPress ban does,
 * and must not depend on `Driver`; `Forbidden` is a module `Core` may not depend on,
 * and `Elsewhere` a namespace no rule selects.
 *
 * no real WordPress class takes part:
 * the test container locates classes through Composer and the loaded classes, not `scanFiles`,
 * and `bootstrapFiles` would load WordPress into PHPUnit's process
 *
 * @extends RuleTestCase<ModuleDependencyTraitRule>
 *
 * @internal
 */
#[CoversClass(ModuleDependencyTraitRule::class)]
final class ModuleDependencyTraitRuleTest extends RuleTestCase
{
    private const MODULES = 'Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\\';

    private const TIP = 'Core may depend on itself and on Allowed but Withheld';

    /**
     * null stands for the settings `phpstan.neon.dist` gives PHPat
     */
    private ?Configuration $configuration = null;

    /**
     * @var ?list<Statement>
     */
    private ?array $statements = null;

    #[Override]
    protected function getRule(): Rule
    {
        return new ModuleDependencyTraitRule(
            $this->statements ?? self::fixtureStatements(),
            $this->configuration ?? new Configuration(false, true, false),
            self::createReflectionProvider(),
            self::getContainer()->getByType(FileTypeMapper::class),
        );
    }

    /**
     * a used trait is analysed together with the classes that use it, as the whole analysis sees it
     *
     * @param list<class-string>               $analysed
     * @param list<array{int, string, string}> $expected line, identifier and message of each error
     */
    #[DataProvider('fixtures')]
    public function testReportsWhatTheFixtureBreaks(array $analysed, array $expected): void
    {
        self::assertSame(self::sorted($expected), self::sorted($this->reported($analysed)));
    }

    /**
     * @return array<string, array{list<class-string>, list<array{int, string, string}>}>
     */
    public static function fixtures(): array
    {
        return [
            'a trait no class uses that writes a forbidden class in every place the rule reads' => [
                [WritesEveryKind::class],
                self::writesEveryKind(),
            ],
            'a trait classes of Core and of another namespace use that writes forbidden classes' => [
                [NamesForbidden::class, UsesNamesForbidden::class, AlsoUsesNamesForbidden::class],
                [
                    self::canOnly(NamesForbidden::class, 36, Borrowed::class),
                    self::canOnly(NamesForbidden::class, 40, ForbiddenValue::class),
                ],
            ],
            'a trait a class uses that writes classes the regular expression of the platform names' => [
                [NamesPlatform::class, UsesNamesPlatform::class],
                [
                    self::canOnly(NamesPlatform::class, 37, WpDatabase::class),
                    self::canOnly(NamesPlatform::class, 39, WpPost::class),
                    self::error(NamesPlatform::class, 37, 'coreModulesDependOnNoPlatformClass', WpDatabase::class),
                    self::error(NamesPlatform::class, 39, 'coreModulesDependOnNoPlatformClass', WpPost::class),
                ],
            ],
            'a trait no class uses that writes a class of a driver' => [
                [NamesDriver::class],
                [
                    self::canOnly(NamesDriver::class, 36, DriverValue::class),
                    self::canOnly(NamesDriver::class, 38, DriverValue::class),
                    self::error(NamesDriver::class, 36, 'coreModulesDependOnNoDriver', DriverValue::class),
                    self::error(NamesDriver::class, 38, 'coreModulesDependOnNoDriver', DriverValue::class),
                ],
            ],
            'a trait classes use that writes allowed and built-in classes' => [
                [NamesAllowed::class, UsesNamesAllowed::class, AlsoUsesNamesAllowed::class],
                [],
            ],
            'a trait no class uses that writes allowed classes and built-in classes only BuiltInClasses lists' => [
                [UnusedNamesAllowed::class],
                [],
            ],
            'a trait a subject exclusion keeps out' => [[Excused::class], []],
            'a trait no rule selects' => [[Unselected::class], []],
            'a class that uses a trait, which the rule does not visit' => [[UsesNamesForbidden::class], []],
        ];
    }

    /**
     * @param list<class-string>               $analysed
     * @param list<array{int, string, string}> $expected line, identifier and message of each error
     */
    #[DataProvider('settings')]
    public function testFollowsTheSettingsOfPhpat(Configuration $configuration, array $analysed, array $expected): void
    {
        $this->configuration = $configuration;

        self::assertSame(self::sorted($expected), self::sorted($this->reported($analysed)));
    }

    /**
     * @return array<string, array{
     *     Configuration,
     *     list<class-string>,
     *     list<array{int, string, string}>,
     * }>
     */
    public static function settings(): array
    {
        $tags = [
            [70, MethodTagValue::class],
            [70, MethodTagParameterValue::class],
            [70, MixinValue::class],
            [70, PropertyTagValue::class],
            [70, PropertyReadTagValue::class],
            [70, PropertyWriteTagValue::class],
            [90, ParamTagValue::class],
            [90, ReturnTagValue::class],
            [90, FailureException::class],
            [101, ForbiddenValue::class],
            [123, TemplateBoundValue::class],
            [126, TemplateBoundValue::class],
        ];

        return [
            'ignore_doc_comments' => [
                new Configuration(true, true, false),
                [WritesEveryKind::class],
                array_values(array_filter(
                    self::writesEveryKind(),
                    static function (array $error) use ($tags): bool {
                        return !in_array(
                            [$error[0], $error[2]],
                            array_map(static function (array $tag): array {
                                return [$tag[0], self::message(WritesEveryKind::class, $tag[1])];
                            }, $tags),
                            true,
                        );
                    },
                )),
            ],
            'ignore_built_in_classes off' => [
                new Configuration(false, false, false),
                [NamesAllowed::class],
                [
                    self::canOnly(NamesAllowed::class, 40, 'DateTimeImmutable'),
                    self::canOnly(NamesAllowed::class, 42, 'DateTimeImmutable'),
                ],
            ],
            'show_rule_names' => [
                new Configuration(false, true, true),
                [NamesForbidden::class],
                [
                    [36, 'phpat.coreModulesDependOnlyOnAllowedModules', 'coreModulesDependOnlyOnAllowedModules: '
                        . self::message(NamesForbidden::class, Borrowed::class)],
                    [40, 'phpat.coreModulesDependOnlyOnAllowedModules', 'coreModulesDependOnlyOnAllowedModules: '
                        . self::message(NamesForbidden::class, ForbiddenValue::class)],
                ],
            ],
        ];
    }

    /**
     * `WpPost` matches both targets, its regular expression and the namespace
     */
    public function testReportsANameSeveralTargetsOfARuleShouldNotMatchOnce(): void
    {
        $this->statements = [new Statement(
            'coreModulesDependOnNoPlatformClass',
            Constraint::ShouldNot,
            Selector::inNamespace(self::MODULES . 'Core'),
            [],
            [
                Selector::classname('/\\\\Platform\\\\WpPost$/', true),
                Selector::inNamespace(self::MODULES . 'Platform'),
            ],
            [],
            [],
            [],
        )];

        self::assertSame(
            self::sorted([
                self::error(NamesPlatform::class, 37, 'coreModulesDependOnNoPlatformClass', WpDatabase::class),
                self::error(NamesPlatform::class, 39, 'coreModulesDependOnNoPlatformClass', WpPost::class),
            ]),
            self::sorted($this->reported([NamesPlatform::class])),
        );
    }

    public function testAnErrorCarriesTheTipsAndTheIgnorabilityOfItsStatement(): void
    {
        $errors = [];
        foreach ($this->gatherAnalyserErrors([self::fileOf(NamesDriver::class)]) as $error) {
            $errors[] = [(string) $error->getIdentifier(), $error->getTip(), $error->canBeIgnored()];
        }

        self::assertSame(
            self::sorted([
                ['phpat.coreModulesDependOnlyOnAllowedModules', self::TIP, true],
                ['phpat.coreModulesDependOnlyOnAllowedModules', self::TIP, true],
                ['phpat.coreModulesDependOnNoDriver', null, false],
                ['phpat.coreModulesDependOnNoDriver', null, false],
            ]),
            self::sorted($errors),
        );
    }

    public function testRefusesARuleShouldDependOn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'a rule should depend on has no reading on a trait, since PHPat applies it to each node of a class,'
            . ' got the rule "coreModulesDependOnAllowed"',
        );

        new ModuleDependencyTraitRule(
            [new Statement(
                'coreModulesDependOnAllowed',
                Constraint::Should,
                Selector::inNamespace(self::MODULES . 'Core'),
                [],
                [Selector::inNamespace(self::MODULES . 'Allowed')],
                [],
                [],
                [],
            )],
            new Configuration(false, true, false),
            self::createStub(ReflectionProvider::class),
            self::getContainer()->getByType(FileTypeMapper::class),
        );
    }

    public function testTheFactoryReadsTheStatementsOfTheDependencyRules(): void
    {
        $statementBuilder = NamingTraitRuleTest::libraryStatementBuilder();
        $configuration = new Configuration(false, true, false);
        $reflectionProvider = self::createStub(ReflectionProvider::class);
        $fileTypeMapper = self::getContainer()->getByType(FileTypeMapper::class);
        $statements = array_values($statementBuilder->build('depend'));

        self::assertEquals(
            new ModuleDependencyTraitRule($statements, $configuration, $reflectionProvider, $fileTypeMapper),
            ModuleDependencyTraitRule::forLibrary(
                $statementBuilder,
                $configuration,
                $reflectionProvider,
                $fileTypeMapper,
            ),
        );
        $rules = [
            'coreModulesDependOnlyOnAllowedModules',
            'coreModulesDependOnNoWordPressClass',
            'coreModulesDependOnNoDriverAndNoRootClass',
            'wordPressAdapterDependsOnCoreAndWordPress',
            'consoleDependsOnNoOtherModule',
            'testingDependsOnNoOtherModule',
        ];
        foreach ($rules as $rule) {
            self::assertNotSame(
                [],
                array_filter($statements, static function (Statement $statement) use ($rule): bool {
                    return str_starts_with($statement->ruleName, $rule);
                }),
                'no statement of ' . $rule,
            );
        }
        foreach ($statements as $statement) {
            self::assertNotSame(
                [],
                array_filter($rules, static function (string $rule) use ($statement): bool {
                    return str_starts_with($statement->ruleName, $rule);
                }),
                'a statement of no rule of ModuleDependencyRules: ' . $statement->ruleName,
            );
        }
    }

    public function testTheConstructorAndTheFactoryForLibraryKeepTheirParameters(): void
    {
        $rule = new ReflectionClass(ModuleDependencyTraitRule::class);
        $names = static function (?ReflectionMethod $method): array {
            return array_map(
                static function (ReflectionParameter $parameter): string {
                    return $parameter->getName();
                },
                $method?->getParameters() ?? [],
            );
        };

        self::assertTrue($rule->isFinal());
        self::assertTrue($rule->implementsInterface(Rule::class));
        self::assertNotNull($rule->getConstructor());
        self::assertSame(
            ['statements', 'configuration', 'reflectionProvider', 'fileTypeMapper'],
            $names($rule->getConstructor()),
        );
        self::assertTrue($rule->getMethod('forLibrary')->isPublic());
        self::assertTrue($rule->getMethod('forLibrary')->isStatic());
        self::assertSame(
            ['statementBuilder', 'configuration', 'reflectionProvider', 'fileTypeMapper'],
            $names($rule->getMethod('forLibrary')),
        );
        self::assertSame(Trait_::class, $this->getRule()->getNodeType());
    }

    public function testThePhpStanConfigurationRegistersTheRuleThroughTheFactory(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 2) . '/phpstan.neon.dist');

        self::assertIsString($configuration);
        self::assertMatchesRegularExpression(
            '/^    -\n        factory: ' . preg_quote(ModuleDependencyTraitRule::class . '::forLibrary', '/')
                . '\n        tags:\n            - phpstan\.rules\.rule$/m',
            $configuration,
        );
    }

    /**
     * @return list<Statement>
     */
    public static function fixtureStatements(): array
    {
        $core = Selector::inNamespace(self::MODULES . 'Core');

        return [
            new Statement(
                'coreModulesDependOnlyOnAllowedModules',
                Constraint::CanOnly,
                $core,
                [Selector::classname(Excused::class)],
                [$core, Selector::inNamespace(self::MODULES . 'Allowed')],
                [Selector::classname(Withheld::class)],
                [self::TIP],
                [],
            ),
            new Statement(
                'coreModulesDependOnNoPlatformClass',
                Constraint::ShouldNot,
                $core,
                [],
                [
                    Selector::classname('/\\\\Platform\\\\WpPost$/', true),
                    Selector::classname('/\\\\Platform\\\\WpDatabase$/', true),
                ],
                [],
                [],
                [],
            ),
            new Statement(
                'coreModulesDependOnNoDriver',
                Constraint::ShouldNot,
                $core,
                [],
                [Selector::inNamespace(self::MODULES . 'Driver')],
                [],
                [],
                [],
                true,
            ),
        ];
    }

    /**
     * @return list<array{int, string, string}>
     */
    private static function writesEveryKind(): array
    {
        $errors = [];
        foreach ([
            [70, Marker::class],
            [70, MethodTagValue::class],
            [70, MethodTagParameterValue::class],
            [70, MixinValue::class],
            [70, PropertyTagValue::class],
            [70, PropertyReadTagValue::class],
            [70, PropertyWriteTagValue::class],
            [73, Borrowed::class],
            [75, Marker::class],
            [76, ForbiddenValue::class],
            [78, Marker::class],
            [78, ForbiddenValue::class],
            [81, ForbiddenValue::class],
            [90, Marker::class],
            [90, ParamTagValue::class],
            [90, ReturnTagValue::class],
            [90, FailureException::class],
            [90, ReturnValue::class],
            [92, Marker::class],
            [92, ForbiddenValue::class],
            [95, UnionValue::class],
            [96, IntersectionValue::class],
            [97, self::MODULES . 'Forbidden\AbsentValue'],
            [98, ForbiddenValue::class],
            [101, ForbiddenValue::class],
            [102, ForbiddenValue::class],
            [103, ForbiddenValue::class],
            [104, ForbiddenValue::class],
            [105, Marker::class],
            [105, ForbiddenValue::class],
            [108, FailureException::class],
            [111, ForbiddenValue::class],
            [123, TemplateBoundValue::class],
            [126, TemplateBoundValue::class],
            [133, Withheld::class],
        ] as [$line, $class]) {
            $errors[] = self::canOnly(WritesEveryKind::class, $line, $class);
        }

        return $errors;
    }

    /**
     * @param list<class-string> $analysed
     *
     * @return list<array{int, string, string}>
     */
    private function reported(array $analysed): array
    {
        return array_map(
            static function (Error $error): array {
                return [(int) $error->getLine(), (string) $error->getIdentifier(), $error->getMessage()];
            },
            $this->gatherAnalyserErrors(array_map(self::fileOf(...), $analysed)),
        );
    }

    /**
     * @return array{int, string, string}
     */
    private static function canOnly(string $trait, int $line, string $class): array
    {
        return self::error($trait, $line, 'coreModulesDependOnlyOnAllowedModules', $class);
    }

    /**
     * @return array{int, string, string}
     */
    private static function error(string $trait, int $line, string $rule, string $class): array
    {
        return [$line, 'phpat.' . $rule, self::message($trait, $class)];
    }

    private static function message(string $trait, string $class): string
    {
        return 'trait ' . $trait . ' should not depend on ' . $class . ', which the module dependency rules forbid';
    }

    /**
     * @template T of array<mixed>
     *
     * @param list<T> $errors
     *
     * @return list<T>
     */
    private static function sorted(array $errors): array
    {
        sort($errors);

        return $errors;
    }

    /**
     * @param class-string $class
     */
    private static function fileOf(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        self::assertIsString($file);

        return $file;
    }
}
