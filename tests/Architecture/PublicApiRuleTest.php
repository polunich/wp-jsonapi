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
use PHPStan\Analyser\Error;
use PHPStan\Node\InClassNode;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPStan\Type\FileTypeMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polunich\WpJsonApi\Access\AllowAny;
use Polunich\WpJsonApi\Access\EndpointAttempt;
use Polunich\WpJsonApi\Access\EndpointPermission;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Api;
use Polunich\WpJsonApi\ApiBuilder;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\ChallengeProvider;
use Polunich\WpJsonApi\Authentication\Identity;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Declaration\QueryParameters;
use Polunich\WpJsonApi\Declaration\WrittenTwice;
use Polunich\WpJsonApi\Document\MemberName;
use Polunich\WpJsonApi\Endpoint\ResponseKind;
use Polunich\WpJsonApi\Error\ErrorCatalog;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ExceptionMapper;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Http\UploadedFile;
use Polunich\WpJsonApi\JsonApi;
use Polunich\WpJsonApi\Localization\ContentLanguage;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Operation\AtomicOperation;
use Polunich\WpJsonApi\Operation\AtomicOperations;
use Polunich\WpJsonApi\Operation\PendingWrite;
use Polunich\WpJsonApi\Query\CollectionReading;
use Polunich\WpJsonApi\Query\QueryText;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\DerivedException;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ExceptedException;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalAliases;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalBase;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalContract;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalFailureException;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalHelpers;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalState;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalUser;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ListedButMarkedInternal;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ListedValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\PublicBaseException;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\SealedInternalChild;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\UnlistedAndUnmarked;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\AssertTags;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\CleanValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\ClosureThisTypes;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\ConstantOfInternalState;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\ConstantValues;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\ExceptedValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\ExtendsInternalBase;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\ExtendsTaggedBaseWithInternalValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\HoldsValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\ImplementsInheritingContract;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\ImplementsInternalContract;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\ImplementsTaggedWithInternalValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\InheritsInternalContract;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\InheritsMembers;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\InternalMembersSkipped;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\LeakingMembers;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\MembersToInherit;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\MethodTags;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\MixinTags;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\Mode;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\NamesMismarkedClasses;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\NamesUnknownClass;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\ParameterDefaults;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\ParameterOutTypes;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\ParameterTypes;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\PropertyDefaults;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\PropertyTags;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\PropertyTypes;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\PublicHelpers;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\RequiresInternalBase;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\RequiresPublicBase;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\ReturnTypes;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\SealedPublicChild;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\SealedTags;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\SelfOutTags;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\Tagged;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\TaggedBase;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\TemplateBounds;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\TemplateDefaults;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\ThrowsInternalFailure;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\TypeAliases;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\UnlistedButMarkedInternal;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\UnusedLeakingMembers;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\UsesInternalTrait;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\UsesLeakingTrait;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\UseTagInAnotherCase;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\UseTags;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace\WidensPrivateMethods;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\Tests\UnmarkedInTheExcludedRoot;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\LibraryShelf\UnmarkedBesideTheRoot;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Outside\UnmarkedOutsideTheRoot;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @extends RuleTestCase<PublicApiRule>
 *
 * @internal
 */
#[CoversClass(PublicApiRule::class)]
#[CoversClass(PublicApiCheck::class)]
final class PublicApiRuleTest extends RuleTestCase
{
    private const FIXTURES = 'Polunich\WpJsonApi\Tests\Fixtures\Architecture\\';

    private const LIBRARY = 'Polunich\WpJsonApi\\';

    #[Override]
    protected function getRule(): Rule
    {
        return new PublicApiRule(new PublicApiCheck(
            self::createReflectionProvider(),
            self::getContainer()->getByType(FileTypeMapper::class),
            self::getContainer()->getByType(TypeNodeResolver::class),
            ...self::fixtureList(),
        ));
    }

    /**
     * @param class-string                $class    analysed alone; the errors carry its start line
     * @param list<array{string, string}> $expected identifier and message of each error
     */
    #[DataProvider('fixtures')]
    public function testReportsWhatTheFixtureBreaks(string $class, array $expected): void
    {
        $declaration = new ReflectionClass($class);
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
            'a class of a public namespace that names only public types' => [CleanValue::class, []],
            'a class of a public namespace that extends an internal parent' => [ExtendsInternalBase::class, [[
                'publicApi.ancestor',
                'class ' . ExtendsInternalBase::class . ' is public API, but it extends internal class '
                    . InternalBase::class,
            ]]],
            'a class of a public namespace that implements an internal'
            . ' interface' => [ImplementsInternalContract::class, [[
                'publicApi.ancestor',
                'class ' . ImplementsInternalContract::class . ' is public API, but it implements internal interface '
                    . InternalContract::class,
            ]]],
            'an interface of a public namespace that extends an internal'
            . ' interface' => [InheritsInternalContract::class, [[
                'publicApi.ancestor',
                'interface ' . InheritsInternalContract::class . ' is public API, but it extends internal interface '
                    . InternalContract::class,
            ]]],
            'a class that implements a public interface whose parent is internal' => [
                ImplementsInheritingContract::class,
                [],
            ],
            'a class that implements a public interface with an'
            . ' internal type argument' => [ImplementsTaggedWithInternalValue::class, [[
                'publicApi.ancestor',
                'class ' . ImplementsTaggedWithInternalValue::class . ' is public API, but it implements interface '
                    . Tagged::class . ' with internal class ' . InternalValue::class,
            ]]],
            'a class that extends a public class with an internal'
            . ' type argument' => [ExtendsTaggedBaseWithInternalValue::class, [[
                'publicApi.ancestor',
                'class ' . ExtendsTaggedBaseWithInternalValue::class . ' is public API, but it extends class '
                    . TaggedBase::class . ' with internal class ' . InternalValue::class,
            ]]],
            'a class that uses an internal trait, whose private method it makes'
            . ' public, and a public trait' => [UsesInternalTrait::class, [
                [
                    'publicApi.ancestor',
                    'class ' . UsesInternalTrait::class . ' is public API, but it uses internal trait '
                        . InternalHelpers::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . UsesInternalTrait::class
                        . ' is public API, but the return type of method secret() names internal class '
                        . InternalValue::class,
                ],
            ]],
            'a public trait, which the rule does not visit' => [PublicHelpers::class, []],
            'a class that takes a leaking constant, property and method from a trait, which the trait reports' => [
                UsesLeakingTrait::class,
                [],
            ],
            'a public trait whose constant, property and method leak, which the rule does not visit' => [
                LeakingMembers::class,
                [],
            ],
            'a public trait no class uses whose members leak, which the rule does not visit' => [
                UnusedLeakingMembers::class,
                [],
            ],
            'an internal trait, which the rule does not visit' => [InternalHelpers::class, []],
            'tags @use with an internal and a listed type argument, and the methods they'
            . ' type, read on the trait' => [UseTags::class, [[
                'publicApi.ancestor',
                'class '
                    . UseTags::class
                    . ' is public API, but it uses trait '
                    . HoldsValue::class
                    . ' with internal class '
                    . InternalValue::class,
            ]]],
            'a tag @use that writes the name of the trait in another case, which PHPStan applies to no trait' => [
                UseTagInAnotherCase::class,
                [],
            ],
            'a public generic trait, which the rule does not visit' => [HoldsValue::class, []],
            'private methods of a trait made public or protected by name, by a'
            . ' new name and by the name of the trait, and methods the traits'
            . ' publish, one taken in place of another' => [WidensPrivateMethods::class, [
                [
                    'publicApi.signature',
                    'class '
                        . WidensPrivateMethods::class
                        . ' is public API, but the return type of method hidden() names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . WidensPrivateMethods::class
                        . ' is public API, but the return type of method exposed() names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . WidensPrivateMethods::class
                        . ' is public API, but the return type of method qualified() names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . WidensPrivateMethods::class
                        . ' is public API, but the return type of method camelCase() names internal class '
                        . InternalValue::class,
                ],
            ]],
            'type aliases declared and imported, internal and listed' => [TypeAliases::class, [
                [
                    'publicApi.signature',
                    'class ' . TypeAliases::class . ' is public API, but type alias Held names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . TypeAliases::class
                        . ' is public API, but imported type alias ImportedInternal names internal class '
                        . InternalValue::class,
                ],
            ]],
            'an internal class whose type aliases a public class imports, marked' => [InternalAliases::class, []],
            'native parameters of a public and a protected method and a PHPDoc parameter' => [ParameterTypes::class, [
                [
                    'publicApi.signature',
                    'class '
                        . ParameterTypes::class
                        . ' is public API, but parameter $value of method accept() names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . ParameterTypes::class
                        . ' is public API, but parameter $value of method acceptLater() names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . ParameterTypes::class
                        . ' is public API, but parameter $values of method acceptAll() names internal class '
                        . InternalValue::class,
                ],
            ]],
            'a native and a PHPDoc return type' => [ReturnTypes::class, [
                [
                    'publicApi.signature',
                    'class '
                        . ReturnTypes::class
                        . ' is public API, but the return type of method first() names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . ReturnTypes::class
                        . ' is public API, but the return type of method all() names internal class '
                        . InternalValue::class,
                ],
            ]],
            'a @throws tag' => [ThrowsInternalFailure::class, [[
                'publicApi.signature',
                'class '
                    . ThrowsInternalFailure::class
                    . ' is public API, but the @throws tag of method fail() names internal class '
                    . InternalFailureException::class,
            ]]],
            'a native and a PHPDoc property type, and a promoted property' => [PropertyTypes::class, [
                [
                    'publicApi.signature',
                    'class ' . PropertyTypes::class . ' is public API, but property $value names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class ' . PropertyTypes::class . ' is public API, but property $states names internal enum '
                        . InternalState::class,
                ],
                [
                    'publicApi.signature',
                    'class ' . PropertyTypes::class . ' is public API, but property $promoted names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . PropertyTypes::class
                        . ' is public API, but parameter $promoted of method __construct() names internal class '
                        . InternalValue::class,
                ],
            ]],
            'a constant' => [ConstantOfInternalState::class, [[
                'publicApi.signature',
                'class ' . ConstantOfInternalState::class . ' is public API, but constant DEFAULT names internal enum '
                    . InternalState::class,
            ]]],
            'the template bounds of a class and of a method' => [TemplateBounds::class, [
                [
                    'publicApi.signature',
                    'class ' . TemplateBounds::class . ' is public API, but template T names internal interface '
                        . InternalContract::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . TemplateBounds::class
                        . ' is public API, but template U of method same() names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . TemplateBounds::class
                        . ' is public API, but parameter $value of method same() names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . TemplateBounds::class
                        . ' is public API, but the return type of method same() names internal class '
                        . InternalValue::class,
                ],
            ]],
            'a param-out tag' => [ParameterOutTypes::class, [[
                'publicApi.signature',
                'class '
                    . ParameterOutTypes::class
                    . ' is public API, but the @param-out tag of parameter $value of method fill() names internal'
                    . ' class '
                    . InternalValue::class,
            ]]],
            'a param-closure-this tag' => [ClosureThisTypes::class, [[
                'publicApi.signature',
                'class '
                    . ClosureThisTypes::class
                    . ' is public API, but the @param-closure-this tag of parameter $callback of method'
                    . ' bindInternal() names internal class '
                    . InternalValue::class,
            ]]],
            'parameter default values: an object, an enum case, a class constant'
            . ' alone and in an array, not an own constant' => [ParameterDefaults::class, [
                [
                    'publicApi.signature',
                    'class '
                        . ParameterDefaults::class
                        . ' is public API, but the default value of parameter $value of method create() names'
                        . ' internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . ParameterDefaults::class
                        . ' is public API, but the default value of parameter $state of method state() names internal'
                        . ' enum '
                        . InternalState::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . ParameterDefaults::class
                        . ' is public API, but the default value of parameter $limit of method limit() names internal'
                        . ' class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . ParameterDefaults::class
                        . ' is public API, but the default value of parameter $limits of method limits() names'
                        . ' internal class '
                        . InternalValue::class,
                ],
            ]],
            'the three kinds of assert tags' => [AssertTags::class, [
                [
                    'publicApi.signature',
                    'class '
                        . AssertTags::class
                        . ' is public API, but the @phpstan-assert tag of method assertInternal() names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . AssertTags::class
                        . ' is public API, but the @phpstan-assert-if-true tag of method isInternal() names internal'
                        . ' class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . AssertTags::class
                        . ' is public API, but the @phpstan-assert-if-false tag of method isOther() names internal'
                        . ' class '
                        . InternalValue::class,
                ],
            ]],
            'a self-out tag' => [SelfOutTags::class, [[
                'publicApi.signature',
                'class '
                    . SelfOutTags::class
                    . ' is public API, but the @phpstan-self-out tag of method holdInternal() names internal class '
                    . InternalValue::class,
            ]]],
            'the three kinds of class-level property tags' => [PropertyTags::class, [
                [
                    'publicApi.signature',
                    'class '
                        . PropertyTags::class
                        . ' is public API, but the @property tag of property $value names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . PropertyTags::class
                        . ' is public API, but the @property-read tag of property $readable names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . PropertyTags::class
                        . ' is public API, but the @property-write tag of property $writable names internal class '
                        . InternalValue::class,
                ],
            ]],
            'method tags: a return type, a parameter, default values but an own constant, a'
            . ' template bound and a template default' => [MethodTags::class, [
                [
                    'publicApi.signature',
                    'class '
                        . MethodTags::class
                        . ' is public API, but the return type of the @method tag of method first() names internal'
                        . ' class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . MethodTags::class
                        . ' is public API, but parameter $value of the @method tag of method accept() names internal'
                        . ' class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . MethodTags::class
                        . ' is public API, but the default value of parameter $limit of the @method tag of method'
                        . ' limit() names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . MethodTags::class
                        . ' is public API, but the default value of parameter $limits of the @method tag of method'
                        . ' limits() names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . MethodTags::class
                        . ' is public API, but the default value of parameter $limits of the @method tag of method'
                        . ' keyed() names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . MethodTags::class
                        . ' is public API, but template U of the @method tag of method same() names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . MethodTags::class
                        . ' is public API, but parameter $value of the @method tag of method same() names internal'
                        . ' class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . MethodTags::class
                        . ' is public API, but the return type of the @method tag of method same() names internal'
                        . ' class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . MethodTags::class
                        . ' is public API, but template V of the @method tag of method made() names internal class '
                        . InternalValue::class,
                ],
            ]],
            'mixin tags' => [MixinTags::class, [[
                'publicApi.signature',
                'class ' . MixinTags::class . ' is public API, but the @mixin tag names internal class '
                    . InternalValue::class,
            ]]],
            'the template defaults of a class and of a method' => [TemplateDefaults::class, [
                [
                    'publicApi.signature',
                    'class ' . TemplateDefaults::class . ' is public API, but template T names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . TemplateDefaults::class
                        . ' is public API, but template U of method same() names internal class '
                        . InternalValue::class,
                ],
            ]],
            'a require-extends tag naming an internal class' => [RequiresInternalBase::class, [[
                'publicApi.signature',
                'interface '
                    . RequiresInternalBase::class
                    . ' is public API, but the @phpstan-require-extends tag names internal class '
                    . InternalBase::class,
            ]]],
            'a require-extends tag naming the public base class' => [RequiresPublicBase::class, []],
            'a sealed tag' => [SealedTags::class, [[
                'publicApi.signature',
                'class ' . SealedTags::class . ' is public API, but the @phpstan-sealed tag names internal class '
                    . SealedInternalChild::class,
            ]]],
            'a public subclass a sealed tag allows' => [SealedPublicChild::class, []],
            'an internal subclass a sealed tag allows, marked' => [SealedInternalChild::class, []],
            'the value of a typed constant, a native and a PHPDoc constant type, a'
            . ' native type a PHPDoc type narrows, and the value type of an untyped'
            . ' constant' => [ConstantValues::class, [
                [
                    'publicApi.signature',
                    'class ' . ConstantValues::class . ' is public API, but constant LIMIT names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class ' . ConstantValues::class . ' is public API, but constant NONE names internal enum '
                        . InternalState::class,
                ],
                [
                    'publicApi.signature',
                    'class ' . ConstantValues::class . ' is public API, but constant VALUES names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class ' . ConstantValues::class . ' is public API, but constant UNTYPED_STATE names internal enum '
                        . InternalState::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . ConstantValues::class
                        . ' is public API, but constant NARROWED_STATE names internal interface '
                        . InternalContract::class,
                ],
            ]],
            'a property default value, not an own constant, and a promoted property'
            . ' whose default is that of its parameter' => [PropertyDefaults::class, [
                [
                    'publicApi.signature',
                    'class '
                        . PropertyDefaults::class
                        . ' is public API, but the default value of property $limit names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . PropertyDefaults::class
                        . ' is public API, but the default value of parameter $promoted of method __construct() names'
                        . ' internal class '
                        . InternalValue::class,
                ],
            ]],
            'members marked internal and private members' => [InternalMembersSkipped::class, []],
            'a class that names a class of the library no file declares' => [NamesUnknownClass::class, []],
            'a public enum whose cases have no values' => [Mode::class, []],
            'a public parent whose constant, property and method leak' => [MembersToInherit::class, [
                [
                    'publicApi.signature',
                    'class ' . MembersToInherit::class . ' is public API, but constant DEFAULT names internal enum '
                        . InternalState::class,
                ],
                [
                    'publicApi.signature',
                    'class ' . MembersToInherit::class . ' is public API, but property $value names internal class '
                        . InternalValue::class,
                ],
                [
                    'publicApi.signature',
                    'class '
                        . MembersToInherit::class
                        . ' is public API, but the return type of method value() names internal class '
                        . InternalValue::class,
                ],
            ]],
            'a class that inherits the leaking members and declares none' => [InheritsMembers::class, []],
            'named classes whose marks disagree with the list' => [NamesMismarkedClasses::class, [[
                'publicApi.signature',
                'class '
                    . NamesMismarkedClasses::class
                    . ' is public API, but the return type of method convert() names internal class '
                    . UnlistedAndUnmarked::class,
            ]]],
            'a listed class marked internal' => [ListedButMarkedInternal::class, [[
                'publicApi.marking',
                'class ' . ListedButMarkedInternal::class . ' is public API, but it carries @internal',
            ]]],
            'an unlisted class of a public namespace marked internal, which'
            . ' leaks' => [UnlistedButMarkedInternal::class, [
                [
                    'publicApi.marking',
                    'class ' . UnlistedButMarkedInternal::class . ' is public API, but it carries @internal',
                ],
                [
                    'publicApi.signature',
                    'class '
                        . UnlistedButMarkedInternal::class
                        . ' is public API, but the return type of method value() names internal class '
                        . InternalValue::class,
                ],
            ]],
            'an unlisted class outside the public namespaces without the mark' => [UnlistedAndUnmarked::class, [[
                'publicApi.marking',
                'class ' . UnlistedAndUnmarked::class . ' is not public API, but it carries no @internal',
            ]]],
            'an internal class of a public namespace, marked' => [ExceptedValue::class, []],
            'a listed class, unmarked' => [ListedValue::class, []],
            'a public base class, unmarked' => [PublicBaseException::class, []],
            'a subclass of a public base class, unmarked' => [DerivedException::class, []],
            'an internal class that extends a public base class, marked' => [ExceptedException::class, []],
            'an unlisted class outside the public namespaces, marked' => [InternalValue::class, []],
            'an unlisted enum outside the public namespaces, marked' => [InternalState::class, []],
            'an unlisted class outside the public namespaces, marked, that names internal types' => [
                InternalUser::class,
                [],
            ],
            'a class below the excluded root, unmarked' => [UnmarkedInTheExcludedRoot::class, []],
            'a class outside the root, unmarked' => [UnmarkedOutsideTheRoot::class, []],
            'a class of a namespace that only begins like the root, unmarked' => [UnmarkedBesideTheRoot::class, []],
        ];
    }

    /**
     * @param array{string, string, list<string>, list<string>, list<string>, list<string>} $list
     */
    #[DataProvider('impossibleLists')]
    public function testRefusesAnImpossibleList(array $list, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new PublicApiCheck(
            self::createStub(ReflectionProvider::class),
            self::getContainer()->getByType(FileTypeMapper::class),
            self::getContainer()->getByType(TypeNodeResolver::class),
            ...$list,
        );
    }

    /**
     * @return array<string, array{
     *     array{string, string, list<string>, list<string>, list<string>, list<string>},
     *     string,
     * }>
     */
    public static function impossibleLists(): array
    {
        [
            $root,
            $excludedRoot,
            $publicNamespaces,
            $internalClasses,
            $publicClasses,
            $publicBaseClasses,
        ] = self::fixtureList();

        return [
            'an empty root' => [
                ['', $excludedRoot, $publicNamespaces, $internalClasses, $publicClasses, $publicBaseClasses],
                'the root is a namespace prefix that ends with a backslash, got ""',
            ],
            'a root without its closing backslash' => [
                [
                    self::FIXTURES . 'Library',
                    $excludedRoot,
                    $publicNamespaces,
                    $internalClasses,
                    $publicClasses,
                    $publicBaseClasses,
                ],
                'the root is a namespace prefix that ends with a backslash, got "' . self::FIXTURES . 'Library"',
            ],
            'a root with a leading backslash' => [
                ['\\' . $root, $excludedRoot, $publicNamespaces, $internalClasses, $publicClasses, $publicBaseClasses],
                'the root is a namespace prefix that ends with a backslash, got "\\' . $root . '"',
            ],
            'a root with an empty label' => [
                [
                    self::FIXTURES . '\Library\\',
                    $excludedRoot,
                    $publicNamespaces,
                    $internalClasses,
                    $publicClasses,
                    $publicBaseClasses,
                ],
                'the root is a namespace prefix that ends with a backslash, got "' . self::FIXTURES . '\Library\"',
            ],
            'a root that ends with a line feed' => [
                [$root . "\n", $excludedRoot, $publicNamespaces, $internalClasses, $publicClasses, $publicBaseClasses],
                'the root is a namespace prefix that ends with a backslash, got "' . $root . "\n" . '"',
            ],
            'a root with a label that starts with a digit' => [
                [
                    self::FIXTURES . '1Library\\',
                    $excludedRoot,
                    $publicNamespaces,
                    $internalClasses,
                    $publicClasses,
                    $publicBaseClasses,
                ],
                'the root is a namespace prefix that ends with a backslash, got "' . self::FIXTURES . '1Library\"',
            ],
            'an excluded root without its closing backslash' => [
                [$root, $root . 'Tests', $publicNamespaces, $internalClasses, $publicClasses, $publicBaseClasses],
                'the excluded root is a namespace prefix below the root "' . $root . '", got "' . $root . 'Tests"',
            ],
            'an excluded root that is the root' => [
                [$root, $root, $publicNamespaces, $internalClasses, $publicClasses, $publicBaseClasses],
                'the excluded root is a namespace prefix below the root "' . $root . '", got "' . $root . '"',
            ],
            'an excluded root outside the root' => [
                [$root, self::FIXTURES
                    . 'Tests\\', $publicNamespaces, $internalClasses, $publicClasses, $publicBaseClasses],
                'the excluded root is a namespace prefix below the root "'
                    . $root
                    . '", got "'
                    . self::FIXTURES
                    . 'Tests\"',
            ],
            'a public namespace without its closing backslash' => [
                [
                    $root,
                    $excludedRoot,
                    [$root . 'PublicNamespace'],
                    $internalClasses,
                    $publicClasses,
                    $publicBaseClasses,
                ],
                'a public namespace is a namespace prefix below the root and outside the excluded root, got "'
                    . $root
                    . 'PublicNamespace"',
            ],
            'a public namespace outside the root' => [
                [
                    $root,
                    $excludedRoot,
                    [self::FIXTURES . 'PublicNamespace\\'],
                    $internalClasses,
                    $publicClasses,
                    $publicBaseClasses,
                ],
                'a public namespace is a namespace prefix below the root and outside the excluded root, got "'
                    . self::FIXTURES
                    . 'PublicNamespace\"',
            ],
            'a public namespace below the excluded root' => [
                [
                    $root,
                    $excludedRoot,
                    [$excludedRoot . 'PublicNamespace\\'],
                    $internalClasses,
                    $publicClasses,
                    $publicBaseClasses,
                ],
                'a public namespace is a namespace prefix below the root and outside the excluded root, got "'
                    . $excludedRoot
                    . 'PublicNamespace\"',
            ],
            'an internal class outside the root' => [
                [
                    $root,
                    $excludedRoot,
                    $publicNamespaces,
                    [UnmarkedOutsideTheRoot::class],
                    $publicClasses,
                    $publicBaseClasses,
                ],
                'an internal class is a class name below the root and outside the excluded root, got "'
                    . UnmarkedOutsideTheRoot::class
                    . '"',
            ],
            'an internal class below the excluded root' => [
                [
                    $root,
                    $excludedRoot,
                    $publicNamespaces,
                    [UnmarkedInTheExcludedRoot::class],
                    $publicClasses,
                    $publicBaseClasses,
                ],
                'an internal class is a class name below the root and outside the excluded root, got "'
                    . UnmarkedInTheExcludedRoot::class
                    . '"',
            ],
            'an internal class that is a namespace prefix' => [
                [
                    $root,
                    $excludedRoot,
                    $publicNamespaces,
                    [$root . 'PublicNamespace\\'],
                    $publicClasses,
                    $publicBaseClasses,
                ],
                'an internal class is a class name below the root and outside the excluded root, got "'
                    . $root
                    . 'PublicNamespace\"',
            ],
            'a public class outside the root' => [
                [
                    $root,
                    $excludedRoot,
                    $publicNamespaces,
                    $internalClasses,
                    [UnmarkedBesideTheRoot::class],
                    $publicBaseClasses,
                ],
                'a public class is a class name below the root and outside the excluded root, got "'
                    . UnmarkedBesideTheRoot::class
                    . '"',
            ],
            'a public class that ends with a line feed' => [
                [
                    $root,
                    $excludedRoot,
                    $publicNamespaces,
                    $internalClasses,
                    [ListedValue::class . "\n"],
                    $publicBaseClasses,
                ],
                'a public class is a class name below the root and outside the excluded root, got "'
                    . ListedValue::class
                    . "\n"
                    . '"',
            ],
            'a public class with a leading backslash' => [
                [
                    $root,
                    $excludedRoot,
                    $publicNamespaces,
                    $internalClasses,
                    ['\\' . ListedValue::class],
                    $publicBaseClasses,
                ],
                'a public class is a class name below the root and outside the excluded root, got "\\'
                    . ListedValue::class
                    . '"',
            ],
            'a public base class below the excluded root' => [
                [
                    $root,
                    $excludedRoot,
                    $publicNamespaces,
                    $internalClasses,
                    $publicClasses,
                    [UnmarkedInTheExcludedRoot::class],
                ],
                'a public base class is a class name below the root and outside the excluded root, got "'
                    . UnmarkedInTheExcludedRoot::class
                    . '"',
            ],
            'a public base class with an empty label' => [
                [
                    $root,
                    $excludedRoot,
                    $publicNamespaces,
                    $internalClasses,
                    $publicClasses,
                    [$root . 'OtherNamespace\\\PublicBaseException'],
                ],
                'a public base class is a class name below the root and outside the excluded root, got "'
                    . $root
                    . 'OtherNamespace\\\PublicBaseException"',
            ],
            'a class that is an internal class and a public class' => [
                [
                    $root,
                    $excludedRoot,
                    $publicNamespaces,
                    [...$internalClasses, ListedValue::class],
                    $publicClasses,
                    $publicBaseClasses,
                ],
                'a class is either an internal class or a public class, got "' . ListedValue::class . '" as both',
            ],
        ];
    }

    public function testTheFactoryHoldsTheListsOfTheLibrary(): void
    {
        $reflectionProvider = self::createStub(ReflectionProvider::class);
        $fileTypeMapper = self::getContainer()->getByType(FileTypeMapper::class);
        $typeNodeResolver = self::getContainer()->getByType(TypeNodeResolver::class);
        $check = new PublicApiCheck(
            $reflectionProvider,
            $fileTypeMapper,
            $typeNodeResolver,
            self::LIBRARY,
            self::LIBRARY . 'Tests\\',
            [
                self::LIBRARY . 'Declaration\\',
                self::LIBRARY . 'Endpoint\\',
                self::LIBRARY . 'Codec\\',
                self::LIBRARY . 'Store\\',
                self::LIBRARY . 'Query\\',
                self::LIBRARY . 'Operation\\',
                self::LIBRARY . 'Testing\\',
            ],
            [
                AtomicOperation::class,
                AtomicOperations::class,
                PendingWrite::class,
                ResponseKind::class,
                QueryText::class,
                CollectionReading::class,
                WrittenTwice::class,
                QueryParameters::class,
            ],
            [
                JsonApi::class,
                ApiBuilder::class,
                Api::class,
                ContentLanguage::class,
                LanguagePreferences::class,
                LanguageRange::class,
                Challenge::class,
                ChallengeProvider::class,
                Identity::class,
                SecurityScheme::class,
                ServerRequest::class,
                Headers::class,
                Content::class,
                UploadedFile::class,
                OperationPermission::class,
                AllowAny::class,
                EndpointPermission::class,
                EndpointAttempt::class,
                OperationAttempt::class,
                MemberName::class,
                ErrorDescription::class,
                ErrorCode::class,
                ExceptionMapper::class,
                ErrorCatalog::class,
            ],
            [JsonApiException::class],
        );

        self::assertEquals($check, PublicApiCheck::forLibrary($reflectionProvider, $fileTypeMapper, $typeNodeResolver));
        self::assertEquals(
            new PublicApiRule($check),
            PublicApiRule::forLibrary($reflectionProvider, $fileTypeMapper, $typeNodeResolver),
        );
    }

    public function testTheConstructorAndTheFactoryForLibraryKeepTheirParameters(): void
    {
        $rule = new ReflectionClass(PublicApiRule::class);
        $check = new ReflectionClass(PublicApiCheck::class);
        $names = static function (?ReflectionMethod $method): array {
            return array_map(
                static function (ReflectionParameter $parameter): string {
                    return $parameter->getName();
                },
                $method?->getParameters() ?? [],
            );
        };
        $services = ['reflectionProvider', 'fileTypeMapper', 'typeNodeResolver'];

        self::assertTrue($rule->isFinal());
        self::assertTrue($rule->implementsInterface(Rule::class));
        self::assertNotNull($rule->getConstructor());
        self::assertSame(['check'], $names($rule->getConstructor()));
        self::assertTrue($rule->getMethod('forLibrary')->isPublic());
        self::assertTrue($rule->getMethod('forLibrary')->isStatic());
        self::assertSame($services, $names($rule->getMethod('forLibrary')));
        self::assertSame(InClassNode::class, $this->getRule()->getNodeType());

        self::assertTrue($check->isFinal());
        self::assertNotNull($check->getConstructor());
        self::assertSame(
            [
                ...$services,
                'root',
                'excludedRoot',
                'publicNamespaces',
                'internalClasses',
                'publicClasses',
                'publicBaseClasses',
            ],
            $names($check->getConstructor()),
        );
        self::assertTrue($check->getMethod('forLibrary')->isPublic());
        self::assertTrue($check->getMethod('forLibrary')->isStatic());
        self::assertSame($services, $names($check->getMethod('forLibrary')));
    }

    public function testThePhpStanConfigurationRegistersTheRuleThroughTheFactory(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 2) . '/phpstan.neon.dist');

        self::assertIsString($configuration);
        self::assertMatchesRegularExpression(
            '/^    -\n        factory: ' . preg_quote(PublicApiRule::class . '::forLibrary', '/')
                . '\n        tags:\n            - phpstan\.rules\.rule$/m',
            $configuration,
        );
    }

    /**
     * the analysis cannot guard its own ignore list:
     * an entry still matches when its path widens or is dropped,
     * a fixture moved to `excludePaths` is never analysed and so never reported unmatched,
     * `reportUnmatchedIgnoredErrors: false` hides a fixture that stopped breaking,
     * and an entry with `paths` is misreported by partial runs.
     * so the test pins the settings and, comments aside, the exact entries
     */
    public function testThePhpStanConfigurationIgnoresWhatTheFixturesBreakByIdentifierAndPath(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 2) . '/phpstan.neon.dist');
        self::assertIsString($configuration);
        $settings = preg_replace('/^[ \t]*#.*\n/m', '', $configuration);
        self::assertIsString($settings);

        self::assertMatchesRegularExpression('/^    paths:\n(?:        - .+\n)*        - tests\n/m', $settings);
        self::assertDoesNotMatchRegularExpression('/^\s*excludePaths:/m', $settings);
        self::assertDoesNotMatchRegularExpression('/^\s*reportUnmatchedIgnoredErrors:/m', $settings);
        if (preg_match('/^    ignoreErrors:\n((?:        .*\n|\n)*)/m', $settings, $ignoreErrors) !== 1) {
            self::fail('phpstan.neon.dist sets no ignoreErrors');
        }
        self::assertDoesNotMatchRegularExpression('/^\s*paths:/m', $ignoreErrors[1]);
        self::assertSame(
            [
                '        -',
                '            identifier: trait.unused',
                '            path: tests/Fixtures/Architecture/Library/PublicNamespace/NestedTraitUses.php',
                '        -',
                '            identifier: trait.unused',
                '            path: tests/Fixtures/Architecture/Library/PublicNamespace/TraitTags.php',
                '        -',
                '            identifier: trait.unused',
                '            path:'
                . ' tests/Fixtures/Architecture/Library/PublicNamespace/UnlistedButMarkedInternalMembers.php',
                '        -',
                '            identifier: trait.unused',
                '            path: tests/Fixtures/Architecture/Library/PublicNamespace/UnusedLeakingMembers.php',
                '        -',
                '            identifier: trait.unused',
                '            path: tests/Fixtures/Architecture/Library/OtherNamespace/UnlistedAndUnmarkedMembers.php',
                '        -',
                '            identifier: trait.unused',
                '            path: tests/Fixtures/Architecture/Modules/Core/Excused.php',
                '        -',
                '            identifier: trait.unused',
                '            path: tests/Fixtures/Architecture/Modules/Core/NamesDriver.php',
                '        -',
                '            identifier: trait.unused',
                '            path: tests/Fixtures/Architecture/Modules/Core/UnusedNamesAllowed.php',
                '        -',
                '            identifier: trait.unused',
                '            path: tests/Fixtures/Architecture/Modules/Core/WritesEveryKind.php',
                '        -',
                '            identifier: trait.unused',
                '            path: tests/Fixtures/Architecture/Modules/Elsewhere/Unselected.php',
                '        -',
                '            identifier: trait.unused',
                '            path: tests/Fixtures/Architecture/Naming/AbstractHelpers.php',
                '        -',
                '            identifier: trait.unused',
                '            path: tests/Fixtures/Architecture/Naming/HelperTrait.php',
                '        -',
                '            identifier: trait.unused',
                '            path: tests/Fixtures/Architecture/Naming/Helpers.php',
                '        -',
                '            identifier: trait.unused',
                '            path: tests/Fixtures/Architecture/Naming/Resource.php',
                '        -',
                '            identifier: phpat.traitsHaveNoSuffix',
                '            path: tests/Fixtures/Architecture/Naming/FormattingTrait.php',
                '        -',
                '            identifier: phpat.traitsHaveNoSuffix',
                '            path: tests/Fixtures/Architecture/Naming/HelperTrait.php',
                '        -',
                '            identifier: phpat.namesAvoidSoftReservedWords',
                '            path: tests/Fixtures/Architecture/Naming/Numeric.php',
                '        -',
                '            identifier: phpat.namesAvoidSoftReservedWords',
                '            path: tests/Fixtures/Architecture/Naming/Resource.php',
                '        -',
                '            identifiers:',
                '                - generics.wrongParent',
                '                - missingType.generics',
                '            path: tests/Fixtures/Architecture/Library/PublicNamespace/UseTagInAnotherCase.php',
                '        -',
                '            identifiers:',
                '                - class.notFound',
                '                - return.unusedType',
                '            path: tests/Fixtures/Architecture/Library/PublicNamespace/NamesUnknownClass.php',
                '        -',
                '            identifier: codeStyle.arrowFunction',
                '            path: tests/Fixtures/Architecture/CodeStyle/ArrowFunctions.php',
                '        -',
                '            identifier: codeStyle.arrowFunction',
                '            path: tests/Fixtures/Architecture/Modules/Core/WritesEveryKind.php',
                '        -',
                '            identifier: codeStyle.qualifiedGlobalName',
                '            path: tests/Fixtures/Architecture/CodeStyle/QualifiedNames.php',
                '        -',
                '            identifier: codeStyle.qualifiedGlobalName',
                '            path: tests/Fixtures/Architecture/CodeStyle/global-names.php',
            ],
            array_values(array_filter(
                explode("\n", $ignoreErrors[1]),
                static function (string $line): bool {
                    return $line !== '';
                },
            )),
        );
    }

    /**
     * in the order of the `PublicApiCheck` constructor after its services;
     * both rule tests run on this one list
     *
     * @return array{string, string, list<string>, list<string>, list<string>, list<string>}
     */
    public static function fixtureList(): array
    {
        return [
            self::FIXTURES . 'Library\\',
            self::FIXTURES . 'Library\Tests\\',
            [self::FIXTURES . 'Library\PublicNamespace\\'],
            [ExceptedValue::class, ExceptedException::class],
            [ListedValue::class, ListedButMarkedInternal::class],
            [PublicBaseException::class],
        ];
    }
}
