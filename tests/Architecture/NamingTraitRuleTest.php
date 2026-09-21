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
use PHPat\Configuration;
use PHPat\Rule\Assertion\Constraint;
use PHPat\Selector\IsTrait;
use PHPat\Selector\Selector;
use PHPat\Statement\Statement;
use PHPat\Statement\StatementBuilder;
use PHPat\Test\RuleValidator;
use PHPat\Test\TestExtractorInterface;
use PHPat\Test\TestParser;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Analyser\Error;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Naming\AbstractHelpers;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Naming\Formatting;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Naming\FormattingTrait;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Naming\Helpers;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Naming\HelperTrait;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Naming\Numeric;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Naming\Resource;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Naming\UsesFormatting;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Naming\UsesFormattingTrait;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Naming\UsesNumeric;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @extends RuleTestCase<NamingTraitRule>
 *
 * @internal
 */
#[CoversClass(NamingTraitRule::class)]
final class NamingTraitRuleTest extends RuleTestCase
{
    private const NAMING = 'Polunich\WpJsonApi\Tests\Fixtures\Architecture\Naming';

    private ?NamingTraitRule $rule = null;

    #[Override]
    protected function getRule(): Rule
    {
        return $this->rule ?? new NamingTraitRule(
            array_values(self::libraryStatementBuilder()->build('beNamed')),
            new Configuration(false, true, false),
            self::createReflectionProvider(),
        );
    }

    /**
     * a used trait is analysed together with the class that uses it, as the whole analysis sees it
     *
     * @param class-string                $trait    the fixture whose start line the errors carry
     * @param list<class-string>          $analysed
     * @param list<array{string, string}> $expected identifier and message of each error
     */
    #[DataProvider('fixtures')]
    public function testReportsWhatTheFixtureBreaks(string $trait, array $analysed, array $expected): void
    {
        $line = (new ReflectionClass($trait))->getStartLine();
        self::assertIsInt($line);
        $files = array_map(self::fileOf(...), $analysed);

        $this->analyse($files, array_map(
            static function (array $error) use ($line): array {
                return [$error[1], $line];
            },
            $expected,
        ));

        $reported = array_map(
            static function (Error $error): array {
                return [(string) $error->getIdentifier(), $error->getMessage()];
            },
            $this->gatherAnalyserErrors($files),
        );
        sort($reported);
        sort($expected);
        self::assertSame($expected, $reported);
    }

    /**
     * @return array<string, array{class-string, list<class-string>, list<array{string, string}>}>
     */
    public static function fixtures(): array
    {
        $suffix = static function (string $trait): array {
            return [
                'phpat.traitsHaveNoSuffix',
                'trait ' . $trait . ' should be named matching the regex /^(?!.*Trait$)/',
            ];
        };
        $reserved = static function (string $trait): array {
            return [
                'phpat.namesAvoidSoftReservedWords',
                'trait ' . $trait . ' should be named matching the regex /\\\(?!(?:enum|numeric|resource)$)[^\\\]+$/i',
            ];
        };

        return [
            'a trait with the suffix Trait no class uses' => [HelperTrait::class, [HelperTrait::class], [
                $suffix(HelperTrait::class),
            ]],
            'a trait with the suffix Trait a class uses' => [FormattingTrait::class, [
                FormattingTrait::class,
                UsesFormattingTrait::class,
            ], [
                $suffix(FormattingTrait::class),
            ]],
            'a trait named with a soft-reserved word no class uses' => [Resource::class, [Resource::class], [
                $reserved(Resource::class),
            ]],
            'a trait named with a soft-reserved word a class uses' => [Numeric::class, [
                Numeric::class,
                UsesNumeric::class,
            ], [
                $reserved(Numeric::class),
            ]],
            'a trait that follows the naming rules no class uses' => [Helpers::class, [Helpers::class], []],
            'a trait that follows the naming rules a class uses' => [
                Formatting::class,
                [Formatting::class, UsesFormatting::class],
                [],
            ],
            'a trait with the prefix Abstract and an abstract method' => [
                AbstractHelpers::class,
                [AbstractHelpers::class],
                [],
            ],
            'a class with the suffix Trait, which the rule does not visit' => [
                UsesFormattingTrait::class,
                [UsesFormattingTrait::class],
                [],
            ],
        ];
    }

    /**
     * @param list<Statement>             $statements
     * @param class-string                $trait
     * @param list<array{string, string}> $expected   identifier and message of each error
     */
    #[DataProvider('statements')]
    public function testAppliesAStatementAsPhpatDoes(
        array $statements,
        bool $showRuleNames,
        string $trait,
        array $expected,
    ): void {
        $this->rule = new NamingTraitRule(
            $statements,
            new Configuration(false, true, $showRuleNames),
            self::createReflectionProvider(),
        );

        $reported = array_map(
            static function (Error $error): array {
                return [(string) $error->getIdentifier(), $error->getMessage()];
            },
            $this->gatherAnalyserErrors([self::fileOf($trait)]),
        );

        self::assertSame($expected, $reported);
    }

    /**
     * @return array<string, array{
     *     list<Statement>,
     *     bool,
     *     class-string,
     *     list<array{string, string}>,
     * }>
     */
    public static function statements(): array
    {
        $named = static function (
            string $ruleName,
            Constraint $constraint,
            string $classname,
            bool $isRegex,
        ): Statement {
            return new Statement(
                $ruleName,
                $constraint,
                Selector::inNamespace(self::NAMING),
                [Selector::classname(Formatting::class)],
                [],
                [],
                [],
                ['isRegex' => $isRegex, 'classname' => $classname],
            );
        };

        return [
            'a name compared whole that differs' => [[$named(
                'namedFormatting',
                Constraint::Should,
                '\\' . Formatting::class . '\\',
                false,
            )], false, Helpers::class, [[
                'phpat.namedFormatting',
                'trait ' . Helpers::class . ' should be named \\' . Formatting::class . '\\',
            ]]],
            'a name compared whole that equals once its separators are trimmed' => [
                [$named('namedHelpers', Constraint::Should, '\\' . Helpers::class . '\\', false)],
                false,
                Helpers::class,
                [],
            ],
            'a rule should not that the name meets' => [[$named(
                'notNamedHelpers',
                Constraint::ShouldNot,
                '/Helpers$/',
                true,
            )], false, Helpers::class, [[
                'phpat.notNamedHelpers',
                'trait ' . Helpers::class . ' should not be named matching the regex /Helpers$/',
            ]]],
            'a rule can only, which reports nothing' => [
                [$named('onlyNamedFormatting', Constraint::CanOnly, '/Formatting$/', true)],
                false,
                Helpers::class,
                [],
            ],
            'a trait a subject exclusion keeps out' => [
                [$named('namedHelpers', Constraint::Should, '/Helpers$/', true)],
                false,
                Formatting::class,
                [],
            ],
            'the setting show_rule_names' => [[$named(
                'namedFormatting',
                Constraint::Should,
                '/Formatting$/',
                true,
            )], true, Helpers::class, [[
                'phpat.namedFormatting',
                'namedFormatting: trait ' . Helpers::class . ' should be named matching the regex /Formatting$/',
            ]]],
        ];
    }

    public function testTheErrorCarriesTheTipsAndTheIgnorabilityOfTheStatement(): void
    {
        $this->rule = new NamingTraitRule(
            [new Statement(
                'namedFormatting',
                Constraint::Should,
                Selector::classname(Helpers::class),
                [],
                [],
                [],
                ['a tip of the fixture rule'],
                ['isRegex' => true, 'classname' => '/Formatting$/'],
                true,
            )],
            new Configuration(false, true, false),
            self::createReflectionProvider(),
        );

        $errors = $this->gatherAnalyserErrors([self::fileOf(Helpers::class)]);

        self::assertCount(1, $errors);
        self::assertSame('a tip of the fixture rule', $errors[0]->getTip());
        self::assertFalse($errors[0]->canBeIgnored());
    }

    public function testTheFactoryReadsTheStatementsOfTheNamingRules(): void
    {
        $statementBuilder = self::libraryStatementBuilder();
        $configuration = new Configuration(false, true, false);
        $reflectionProvider = self::createStub(ReflectionProvider::class);

        self::assertEquals(
            new NamingTraitRule(array_values($statementBuilder->build('beNamed')), $configuration, $reflectionProvider),
            NamingTraitRule::forLibrary($statementBuilder, $configuration, $reflectionProvider),
        );
        self::assertSame(
            [
                'exceptionsEndWithException',
                'interfacesHaveNoSuffix',
                'traitsHaveNoSuffix',
                'abstractClassesHaveNoPrefix',
                'namesAvoidSoftReservedWords',
            ],
            array_map(static function (Statement $statement): string {
                return $statement->ruleName;
            }, $statementBuilder->build('beNamed')),
        );
    }

    /**
     * PHPat never checks a trait declaration on its own, so each kind of rule needs a trait rule:
     * naming and dependency have one, and PHP refuses `final` and `readonly` on a trait,
     * so those rules must exclude traits. a rule of any other kind fails here until it gets one
     */
    public function testEveryRuleOfTheRepositoryReachesTheTraitsOrExcludesThem(): void
    {
        $statementBuilder = self::libraryStatementBuilder();
        $kinds = [];
        foreach (self::libraryRules() as $rule) {
            $kinds[] = (string) $rule->getAssertionType();
        }

        self::assertSame(
            ['beFinal', 'beNamed', 'beReadonly', 'depend'],
            array_values(array_unique(self::sorted($kinds))),
        );
        foreach (['beFinal', 'beReadonly'] as $kind) {
            foreach ($statementBuilder->build($kind) as $statement) {
                self::assertNotSame(
                    [],
                    array_filter($statement->subjectExcludes, static function (object $exclude): bool {
                        return $exclude instanceof IsTrait;
                    }),
                    $statement->ruleName . ' excludes no trait',
                );
            }
        }
    }

    public function testTheConstructorAndTheFactoryForLibraryKeepTheirParameters(): void
    {
        $rule = new ReflectionClass(NamingTraitRule::class);
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
        self::assertSame(['statements', 'configuration', 'reflectionProvider'], $names($rule->getConstructor()));
        self::assertTrue($rule->getMethod('forLibrary')->isPublic());
        self::assertTrue($rule->getMethod('forLibrary')->isStatic());
        self::assertSame(
            ['statementBuilder', 'configuration', 'reflectionProvider'],
            $names($rule->getMethod('forLibrary')),
        );
        self::assertSame(Trait_::class, $this->getRule()->getNodeType());
    }

    /**
     * `libraryParser()` names the classes tagged `phpat.test` itself,
     * so the configuration must tag exactly these two
     */
    public function testThePhpStanConfigurationRegistersTheRuleAndTheRulesOfPhpat(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 2) . '/phpstan.neon.dist');

        self::assertIsString($configuration);
        self::assertMatchesRegularExpression(
            '/^    -\n        factory: ' . preg_quote(NamingTraitRule::class . '::forLibrary', '/')
                . '\n        tags:\n            - phpstan\.rules\.rule$/m',
            $configuration,
        );
        preg_match_all('/^        class: (\S+)\n        tags:\n            - phpat\.test$/m', $configuration, $tagged);
        self::assertSame([ModuleDependencyRules::class, NamingRules::class], $tagged[1]);
        self::assertSame(2, substr_count($configuration, 'phpat.test'));
    }

    /**
     * built as the PHPStan container builds it,
     * from the classes `phpstan.neon.dist` tags `phpat.test`.
     * `TestParser` keeps the first rules it parses in a static property for the whole process,
     * so every caller must build it from these same two classes
     */
    public static function libraryStatementBuilder(): StatementBuilder
    {
        return new StatementBuilder(self::libraryParser());
    }

    /**
     * @return list<\PHPat\Test\Rule>
     */
    private static function libraryRules(): array
    {
        return array_values((self::libraryParser())());
    }

    private static function libraryParser(): TestParser
    {
        return new TestParser(
            new class implements TestExtractorInterface {
                public function __invoke(): iterable
                {
                    foreach ([new ModuleDependencyRules(), new NamingRules()] as $test) {
                        yield self::reflected($test);
                    }
                }

                /**
                 * @return array{ReflectionClass<object>, object}
                 */
                private static function reflected(object $test): array
                {
                    return [new ReflectionClass($test), $test];
                }
            },
            new RuleValidator(),
        );
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
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
