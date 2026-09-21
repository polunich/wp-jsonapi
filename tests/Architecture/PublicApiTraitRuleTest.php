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
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Analyser\Error;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPStan\Type\FileTypeMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalContract;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalHelpers;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalState;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\UnlistedAndUnmarkedMembers;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\HoldsValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\KeepsValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\LeakingMembers;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\NestedTraitUses;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\PrivateMethods;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\PublicHelpers;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\TraitTags;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\UnlistedButMarkedInternalMembers;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\UnusedLeakingMembers;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\UsesLeakingTrait;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\WidensPrivateMethods;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @extends RuleTestCase<PublicApiTraitRule>
 *
 * @internal
 */
#[CoversClass(PublicApiTraitRule::class)]
#[CoversClass(PublicApiCheck::class)]
final class PublicApiTraitRuleTest extends RuleTestCase
{
    #[Override]
    protected function getRule(): Rule
    {
        $reflectionProvider = self::createReflectionProvider();

        return new PublicApiTraitRule(
            new PublicApiCheck(
                $reflectionProvider,
                self::getContainer()->getByType(FileTypeMapper::class),
                self::getContainer()->getByType(TypeNodeResolver::class),
                ...PublicApiRuleTest::fixtureList(),
            ),
            $reflectionProvider,
        );
    }

    /**
     * @param class-string                $trait    analysed alone, so no class uses it here
     *                                              even where the repository has one;
     *                                              the errors carry its start line
     * @param list<array{string, string}> $expected identifier and message of each error
     */
    #[DataProvider('fixtures')]
    public function testReportsWhatTheFixtureBreaks(string $trait, array $expected): void
    {
        $declaration = new ReflectionClass($trait);
        $file = $declaration->getFileName();
        $line = $declaration->getStartLine();
        self::assertIsString($file);
        self::assertIsInt($line);

        $this->analyse([$file], array_map(
            static function (array $error) use ($line): array {
                return [$error[1], $line];
            },
            $expected,
        ));

        $reported = array_map(
            static function (Error $error): array {
                return [(string) $error->getIdentifier(), $error->getMessage()];
            },
            $this->gatherAnalyserErrors([$file]),
        );
        sort($reported);
        sort($expected);
        self::assertSame($expected, $reported);
    }

    /**
     * @return array<string, array{class-string, list<array{string, string}>}>
     */
    public static function fixtures(): array
    {
        return [
            'a public trait whose constant, property and method leak, which classes use' => [LeakingMembers::class, [
                [
                    'publicApi.signature',
                    'trait ' . LeakingMembers::class . ' is public API, but constant DEFAULT names internal enum '
                        . InternalState::class,
                ],
                [
                    'publicApi.signature',
                    'trait ' . LeakingMembers::class . ' is public API, but property $value names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'trait '
                        . LeakingMembers::class
                        . ' is public API, but the return type of method value() names internal class '
                        . InternalValue::class,
                ],
            ]],
            'a public trait no class uses whose constant, property and method leak' => [UnusedLeakingMembers::class, [
                [
                    'publicApi.signature',
                    'trait ' . UnusedLeakingMembers::class . ' is public API, but constant DEFAULT names internal enum '
                        . InternalState::class,
                ],
                [
                    'publicApi.signature',
                    'trait ' . UnusedLeakingMembers::class . ' is public API, but property $value names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'trait '
                        . UnusedLeakingMembers::class
                        . ' is public API, but the return type of method value() names internal class '
                        . InternalValue::class,
                ],
            ]],
            'the PHPDoc of a public trait: a template bound, require-implements tags and'
            . ' type aliases' => [TraitTags::class, [
                [
                    'publicApi.signature',
                    'trait ' . TraitTags::class . ' is public API, but template T names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'trait '
                        . TraitTags::class
                        . ' is public API, but the @phpstan-require-implements tag names internal interface '
                        . InternalContract::class,
                ],
                [
                    'publicApi.signature',
                    'trait ' . TraitTags::class . ' is public API, but type alias Held names internal class '
                        . InternalValue::class,
                ],
            ]],
            'a public trait that uses traits: an internal one, a public one, and tags'
            . ' @use' => [NestedTraitUses::class, [
                [
                    'publicApi.ancestor',
                    'trait ' . NestedTraitUses::class . ' is public API, but it uses internal trait '
                        . InternalHelpers::class,
                ],
                [
                    'publicApi.ancestor',
                    'trait '
                        . NestedTraitUses::class
                        . ' is public API, but it uses trait '
                        . HoldsValue::class
                        . ' with internal class '
                        . InternalValue::class,
                ],
            ]],
            'a public trait whose private methods a class publishes, and whose public'
            . ' method leaks' => [PrivateMethods::class, [[
                'publicApi.signature',
                'trait '
                    . PrivateMethods::class
                    . ' is public API, but the return type of method shown() names internal class '
                    . InternalValue::class,
            ]]],
            'a public generic trait whose type argument a tag @use gives' => [HoldsValue::class, []],
            'a second public generic trait' => [KeepsValue::class, []],
            'a public trait, unmarked' => [PublicHelpers::class, []],
            'an internal trait, marked' => [InternalHelpers::class, []],
            'an unlisted trait of a public namespace marked internal,'
            . ' which leaks' => [UnlistedButMarkedInternalMembers::class, [
                [
                    'publicApi.marking',
                    'trait ' . UnlistedButMarkedInternalMembers::class . ' is public API, but it carries @internal',
                ],
                [
                    'publicApi.signature',
                    'trait '
                        . UnlistedButMarkedInternalMembers::class
                        . ' is public API, but the return type of method value() names internal class '
                        . InternalValue::class,
                ],
            ]],
            'an unlisted trait outside the public namespaces without the mark' => [UnlistedAndUnmarkedMembers::class, [[
                'publicApi.marking',
                'trait ' . UnlistedAndUnmarkedMembers::class . ' is not public API, but it carries no @internal',
            ]]],
            'a class that takes a leaking constant, property and method from a trait, which the rule does not'
            . ' visit' => [
                UsesLeakingTrait::class,
                [],
            ],
            'a class that publishes private methods of a trait, which the rule does not visit' => [
                WidensPrivateMethods::class,
                [],
            ],
        ];
    }

    public function testTheFactoryHoldsTheListsOfTheLibrary(): void
    {
        $reflectionProvider = self::createStub(ReflectionProvider::class);
        $fileTypeMapper = self::getContainer()->getByType(FileTypeMapper::class);
        $typeNodeResolver = self::getContainer()->getByType(TypeNodeResolver::class);

        self::assertEquals(
            new PublicApiTraitRule(
                PublicApiCheck::forLibrary($reflectionProvider, $fileTypeMapper, $typeNodeResolver),
                $reflectionProvider,
            ),
            PublicApiTraitRule::forLibrary($reflectionProvider, $fileTypeMapper, $typeNodeResolver),
        );
    }

    public function testTheConstructorAndTheFactoryForLibraryKeepTheirParameters(): void
    {
        $rule = new ReflectionClass(PublicApiTraitRule::class);
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
        self::assertSame(['check', 'reflectionProvider'], $names($rule->getConstructor()));
        self::assertTrue($rule->getMethod('forLibrary')->isPublic());
        self::assertTrue($rule->getMethod('forLibrary')->isStatic());
        self::assertSame(
            ['reflectionProvider', 'fileTypeMapper', 'typeNodeResolver'],
            $names($rule->getMethod('forLibrary')),
        );
        self::assertSame(Trait_::class, $this->getRule()->getNodeType());
    }

    public function testThePhpStanConfigurationRegistersTheRuleThroughTheFactory(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 2) . '/phpstan.neon.dist');

        self::assertIsString($configuration);
        self::assertMatchesRegularExpression(
            '/^    -\n        factory: ' . preg_quote(PublicApiTraitRule::class . '::forLibrary', '/')
                . '\n        tags:\n            - phpstan\.rules\.rule$/m',
            $configuration,
        );
    }
}
