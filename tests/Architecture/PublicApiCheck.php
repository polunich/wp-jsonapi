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
use PhpParser\Modifiers;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\NodeFinder;
use PHPStan\Analyser\NameScope;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\Tag\PropertyTag;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprArrayNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Type;
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

/**
 * a class is public API when the list names it a public class,
 * when it is or extends a public base class, or when it lies in a public namespace,
 * unless the list names it internal.
 * every other class below the root is not; a trait is read from the list like a class.
 * the classes below the excluded root are left out,
 * as the Symfony backward compatibility promise leaves out its `*\Tests\` namespaces.
 * for each class, interface, enum and trait below the root the check reports:
 *
 * - `publicApi.marking`, where `@internal` disagrees with the list:
 *   a public API class carries none, every other class carries it;
 * - `publicApi.ancestor`, where a public API class extends, implements or uses a type
 *   that is not public API, or passes one as a type argument
 *   in its `@extends`, `@implements` or `@use` tag.
 *   only the ancestors the class names itself are read;
 *   a farther one is checked where it is named;
 * - `publicApi.signature`, where a public API class names a class that is not public API:
 *   - in its own PHPDoc: templates with bound and default;
 *     `@property`, `@property-read` and `@property-write`;
 *     `@method` with its templates, parameter types, defaults and return type;
 *     `@mixin`, `@phpstan-require-extends`, `@phpstan-require-implements` and `@phpstan-sealed`;
 *     type aliases declared with `@phpstan-type` or imported with `@phpstan-import-type`,
 *     each by the type it resolves to;
 *   - in a public or protected constant: its native and PHPDoc type,
 *     or the type of its value where it declares neither, and the class names its value writes;
 *   - in a public or protected property: its native and PHPDoc type
 *     and the class names its default writes.
 *     a promoted property has no default of its own,
 *     so the default of the constructor parameter is read instead;
 *   - in a public or protected method: templates with bound and default;
 *     the native and PHPDoc type of each parameter, its `@param-out`, its `@param-closure-this`
 *     and the class names its default writes; the native and PHPDoc return type; `@throws`;
 *     `@phpstan-assert`, `@phpstan-assert-if-true`, `@phpstan-assert-if-false`
 *     and `@phpstan-self-out`.
 *
 *   an initializer names a class only by the class names it writes
 *   (`Name::CONSTANT`, `Name::class`, `new Name()`, ...), never by the type of its value,
 *   which the declared type covers; an untyped constant is the one exception.
 *   `self`, `static` and `parent` name no class here: the class itself and its parent
 *   are covered as the class and by `publicApi.ancestor`.
 *   a member marked `@internal` and a private member are skipped.
 *
 * whether a named type or an ancestor is public API comes from the list, never from its tag;
 * the tag counts only for `publicApi.marking` and for members.
 * so a class with a wrong tag is reported once,
 * and a leak through it is reported whatever its tag says.
 * a type outside the root or below the excluded root is no type of the library,
 * and a class PHPStan does not know is left to PHPStan, which reports unknown classes from level 0.
 *
 * a trait is checked on its own declaration, used or not,
 * so a member a class takes from a trait is read on the trait only, and one leak gives one report.
 * of a trait it uses, a class reads only what its own declaration writes:
 * the trait and the type arguments of its `@use` tag under `publicApi.ancestor`,
 * and under `publicApi.signature` a private trait method
 * that its trait use makes public or protected with an alias,
 * since the class publishes that method and the trait does not.
 * an alias changes the visibility of a method only.
 * every other member of a trait that is not public API is left out,
 * as a member of an internal parent is:
 * the class reports the trait once, under `publicApi.ancestor`
 *
 * @internal
 */
final class PublicApiCheck
{
    private const ROOT = 'Polunich\WpJsonApi\\';

    /**
     * labels joined by backslashes, each matching the pattern the PHP manual gives for a class name
     */
    private const CLASS_NAME = '/^(?:[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\\\\)*'
        . '[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D';

    /**
     * labels, each followed by a backslash:
     * the trailing backslash keeps a prefix from matching a namespace that only begins like it
     */
    private const NAMESPACE_PREFIX = '/^(?:[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\\\\)+$/D';

    /**
     * @param FileTypeMapper   $fileTypeMapper    resolves the `@use` tags of trait uses
     * @param TypeNodeResolver $typeNodeResolver  resolves a type alias
     * @param string           $root              the namespace prefix of the checked classes
     * @param string           $excludedRoot      a namespace prefix below the root, left out
     * @param list<string>     $publicNamespaces  namespace prefixes whose classes are public API
     * @param list<string>     $internalClasses   exceptions to the public namespaces and bases
     * @param list<string>     $publicClasses     classes that are public API one by one
     * @param list<string>     $publicBaseClasses classes that are public API with every class
     *                                            that extends or implements them
     *
     * @throws InvalidArgumentException for a list the check could never apply:
     *                                  a malformed prefix or name,
     *                                  one outside the root or below the excluded root,
     *                                  or a class listed both public and internal
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly FileTypeMapper $fileTypeMapper,
        private readonly TypeNodeResolver $typeNodeResolver,
        private readonly string $root,
        private readonly string $excludedRoot,
        private readonly array $publicNamespaces,
        private readonly array $internalClasses,
        private readonly array $publicClasses,
        private readonly array $publicBaseClasses,
    ) {
        if (preg_match(self::NAMESPACE_PREFIX, $root) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'the root is a namespace prefix that ends with a backslash, got "%s"',
                $root,
            ));
        }

        if (
            preg_match(self::NAMESPACE_PREFIX, $excludedRoot) !== 1
            || $excludedRoot === $root
            || !str_starts_with($excludedRoot, $root)
        ) {
            throw new InvalidArgumentException(sprintf(
                'the excluded root is a namespace prefix below the root "%s", got "%s"',
                $root,
                $excludedRoot,
            ));
        }

        foreach ($publicNamespaces as $namespace) {
            if (preg_match(self::NAMESPACE_PREFIX, $namespace) !== 1 || !$this->isChecked($namespace)) {
                throw new InvalidArgumentException(sprintf(
                    'a public namespace is a namespace prefix below the root and outside the excluded root, got "%s"',
                    $namespace,
                ));
            }
        }

        foreach ([
            'an internal class' => $internalClasses,
            'a public class' => $publicClasses,
            'a public base class' => $publicBaseClasses,
        ] as $role => $names) {
            foreach ($names as $name) {
                if (preg_match(self::CLASS_NAME, $name) !== 1 || !$this->isChecked($name)) {
                    throw new InvalidArgumentException(sprintf(
                        '%s is a class name below the root and outside the excluded root, got "%s"',
                        $role,
                        $name,
                    ));
                }
            }
        }

        foreach ($internalClasses as $name) {
            if (in_array($name, $publicClasses, true)) {
                throw new InvalidArgumentException(sprintf(
                    'a class is either an internal class or a public class, got "%s" as both',
                    $name,
                ));
            }
        }
    }

    /**
     * the internal classes inside public namespaces are ones the library alone builds and reads:
     * `AtomicOperation`, `AtomicOperations`, `PendingWrite` and `CollectionReading`
     * carry what the parser read of a request to its execution;
     * `ResponseKind` is read off a response, which a handler builds by its named constructors;
     * `QueryText` reads a query value for the parser and writes its shape for the contract;
     * `WrittenTwice` and `QueryParameters` serve the declaration builders
     */
    public static function forLibrary(
        ReflectionProvider $reflectionProvider,
        FileTypeMapper $fileTypeMapper,
        TypeNodeResolver $typeNodeResolver,
    ): self {
        return new self(
            $reflectionProvider,
            $fileTypeMapper,
            $typeNodeResolver,
            self::ROOT,
            self::ROOT . 'Tests\\',
            [
                self::ROOT . 'Declaration\\',
                self::ROOT . 'Endpoint\\',
                self::ROOT . 'Codec\\',
                self::ROOT . 'Store\\',
                self::ROOT . 'Query\\',
                self::ROOT . 'Operation\\',
                self::ROOT . 'Testing\\',
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
            [
                JsonApiException::class,
            ],
        );
    }

    /**
     * @param ClassLike $declaration its trait uses carry the `@use` tags and the aliases
     * @param Scope     $scope       its file gives the name scope the `@use` tags are resolved in
     *
     * @return list<IdentifierRuleError>
     */
    public function check(ClassReflection $class, ClassLike $declaration, Scope $scope): array
    {
        if (!$this->isChecked($class->getName())) {
            return [];
        }

        $isPublicApi = $this->isPublicApi($class);
        $errors = [];
        if ($isPublicApi === $class->isInternal()) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                $isPublicApi
                    ? '%s %s is public API, but it carries @internal'
                    : '%s %s is not public API, but it carries no @internal',
                self::kind($class),
                $class->getName(),
            ))->identifier('publicApi.marking')->build();
        }

        if (!$isPublicApi) {
            return $errors;
        }

        return [
            ...$errors,
            ...$this->ancestorErrors($class, $declaration, $scope),
            ...$this->signatureErrors($class, $declaration),
        ];
    }

    /**
     * the PHPStan reflections of the parent and the interfaces
     * already carry the type arguments of `@extends` and `@implements`;
     * a trait gets those of its `@use` tag, or is read by name where no tag describes it
     *
     * @return list<IdentifierRuleError>
     */
    private function ancestorErrors(ClassReflection $class, ClassLike $declaration, Scope $scope): array
    {
        $ancestors = [];
        $parent = $class->getParentClass();
        if ($parent !== null) {
            $ancestors[] = ['extends', $parent, $parent->getObjectType()];
        }
        foreach ($class->getImmediateInterfaces() as $interface) {
            $ancestors[] = [$class->isInterface() ? 'extends' : 'implements', $interface, $interface->getObjectType()];
        }
        $useTypes = $this->useTypes($class, $declaration, $scope);
        foreach ($class->getTraits() as $trait) {
            $ancestors[] = ['uses', $trait, $useTypes[$trait->getName()] ?? $trait->getName()];
        }

        $errors = [];
        foreach ($ancestors as [$verb, $ancestor, $type]) {
            foreach ($this->internalTypes([$type]) as $internal) {
                $errors[] = RuleErrorBuilder::message(
                    $internal->getName() === $ancestor->getName()
                        ? sprintf(
                            '%s %s is public API, but it %s internal %s %s',
                            self::kind($class),
                            $class->getName(),
                            $verb,
                            self::kind($internal),
                            $internal->getName(),
                        )
                        : sprintf(
                            '%s %s is public API, but it %s %s %s with internal %s %s',
                            self::kind($class),
                            $class->getName(),
                            $verb,
                            self::kind($ancestor),
                            $ancestor->getName(),
                            self::kind($internal),
                            $internal->getName(),
                        ),
                )->identifier('publicApi.ancestor')->build();
            }
        }

        return $errors;
    }

    /**
     * the `@use` types of the trait uses, keyed by the trait name as the type writes it.
     * a class reflection carries no `@use` tag, so the tag is resolved from the doc comment
     * of the trait use, in the name scope of the declaration.
     * PHPStan applies a tag only to the trait whose name it writes exactly, letter case included;
     * a tag that describes no used trait is left to PHPStan, which reports it.
     * a `@use` tag holds one name with its type arguments, so its type names one class at most
     *
     * @return array<string, Type>
     */
    private function useTypes(ClassReflection $class, ClassLike $declaration, Scope $scope): array
    {
        $types = [];
        foreach ($declaration->getTraitUses() as $traitUse) {
            $docComment = $traitUse->getDocComment();
            if ($docComment === null) {
                continue;
            }
            $phpDoc = $this->fileTypeMapper->getResolvedPhpDoc(
                $scope->getFile(),
                $class->getName(),
                null,
                null,
                $docComment->getText(),
            );
            foreach ($phpDoc->getUsesTags() as $tag) {
                foreach ($tag->getType()->getObjectClassNames() as $name) {
                    $types[$name] = $tag->getType();
                }
            }
        }

        return $types;
    }

    /**
     * only the members the class declares itself:
     * an inherited member is checked where it is declared, a trait member on the trait,
     * unless the class widens a private trait method with an alias.
     * a trait member is told apart through BetterReflection:
     * the native reflection names the using class as its declaring class, as PHP does,
     * and BetterReflection names the trait.
     * an enum case is skipped: it names its own enum,
     * and PHPStan has no value type for the case of an enum without values.
     *
     * a typed constant is read by its native and PHPDoc types as declared:
     * `getValueType()` combines them as `TypehintHelper::decideType()` does,
     * which keeps a narrowing PHPDoc type alone and so loses a class the native type names
     *
     * @return list<IdentifierRuleError>
     */
    private function signatureErrors(ClassReflection $class, ClassLike $declaration): array
    {
        $places = $this->phpDocPlaces($class);

        $native = $class->getNativeReflection();
        foreach ($native->getReflectionConstants() as $constant) {
            if (
                $constant->isEnumCase()
                || $constant->isPrivate()
                || $constant->getBetterReflection()->getDeclaringClass()->getName() !== $class->getName()
            ) {
                continue;
            }
            $reflection = $class->getConstant($constant->getName());
            if ($reflection->isInternal()->yes()) {
                continue;
            }
            $places['constant ' . $constant->getName()] = [
                ...$reflection->hasNativeType() || $reflection->hasPhpDocType()
                    ? [$reflection->getNativeType(), $reflection->getPhpDocType()]
                    : [$reflection->getValueType()],
                ...self::classNames($reflection->getValueExpr()),
            ];
        }

        foreach ($native->getProperties() as $property) {
            if (
                $property->isPrivate()
                || $property->getBetterReflection()->getDeclaringClass()->getName() !== $class->getName()
            ) {
                continue;
            }
            $reflection = $class->getNativeProperty($property->getName());
            if ($reflection->isInternal()->yes()) {
                continue;
            }
            $name = $property->getName();
            $places['property $' . $name] = [$reflection->getNativeType(), $reflection->getPhpDocType()];
            if ($property->isPromoted()) {
                continue;
            }
            $default = $property->getBetterReflection()->getDefaultValueExpression();
            if ($default !== null) {
                $places['the default value of property $' . $name] = self::classNames($default);
            }
        }

        $widened = $this->widenedMethods($class, $declaration);
        foreach ($native->getMethods() as $method) {
            if (
                $method->isPrivate()
                || $method->getDeclaringClass()->getName() !== $class->getName()
                || (
                    $method->getBetterReflection()->getDeclaringClass()->getName() !== $class->getName()
                    && !isset($widened[strtolower($method->getName())])
                )
            ) {
                continue;
            }
            $reflection = $class->getNativeMethod($method->getName());
            if ($reflection->isInternal()->yes()) {
                continue;
            }
            $of = ' of method ' . $method->getName() . '()';
            foreach ($reflection->getVariants() as $variant) {
                foreach ($variant->getTemplateTypeMap()->getTypes() as $template => $type) {
                    $places['template ' . $template . $of][] = $type;
                    if ($type instanceof TemplateType) {
                        $places['template ' . $template . $of][] = $type->getDefault();
                    }
                }
                foreach ($variant->getParameters() as $parameter) {
                    $parameterOf = ' $' . $parameter->getName() . $of;
                    $places['parameter' . $parameterOf][] = $parameter->getNativeType();
                    $places['parameter' . $parameterOf][] = $parameter->getPhpDocType();
                    $places['the @param-out tag of parameter' . $parameterOf][] = $parameter->getOutType();
                    $places['the @param-closure-this tag of'
                        . ' parameter' . $parameterOf][] = $parameter->getClosureThisType();
                }
                $places['the return type' . $of][] = $variant->getNativeReturnType();
                $places['the return type' . $of][] = $variant->getPhpDocReturnType();
            }
            foreach ($method->getParameters() as $parameter) {
                $default = $parameter->getBetterReflection()->getDefaultValueExpression();
                if ($default !== null) {
                    $places['the default value of parameter $' . $parameter->getName() . $of] = self::classNames(
                        $default,
                    );
                }
            }
            $places['the @throws tag' . $of] = [$reflection->getThrowType()];
            foreach ($reflection->getAsserts()->getAll() as $assert) {
                $tag = $assert->getIf() === '' ? '@phpstan-assert' : '@phpstan-assert-if-' . $assert->getIf();
                $places['the ' . $tag . ' tag' . $of][] = $assert->getType();
            }
            $places['the @phpstan-self-out tag' . $of] = [$reflection->getSelfOutType()];
        }

        $errors = [];
        foreach ($places as $where => $types) {
            foreach ($this->internalTypes($types) as $internal) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    '%s %s is public API, but %s names internal %s %s',
                    self::kind($class),
                    $class->getName(),
                    $where,
                    self::kind($internal),
                    $internal->getName(),
                ))->identifier('publicApi.signature')->build();
            }
        }

        return $errors;
    }

    /**
     * a `@method` default is resolved with `NameScope::resolveStringName()`,
     * which, unlike the resolver PHPStan uses, does not map `self`, `static` and `parent`
     * to the class and its parent, so those three name no class here, as in a native default.
     *
     * a type alias is resolved by name in the name scope of the PHPDoc,
     * whose alias map holds the declared and the imported aliases,
     * so an imported alias resolves to the type it stands for.
     * the class it is imported from is not read by itself: the alias publishes only its type
     *
     * @return array<string, list<Type|string|null>>
     */
    private function phpDocPlaces(ClassReflection $class): array
    {
        $places = [];
        foreach ($class->getTemplateTags() as $name => $tag) {
            $places['template ' . $name] = [$tag->getBound(), $tag->getDefault()];
        }

        foreach ($class->getPropertyTags() as $name => $tag) {
            $places['the ' . self::propertyTagName($tag) . ' tag of property $' . $name] = [
                $tag->getReadableType(),
                $tag->getWritableType(),
            ];
        }

        $defaultNames = self::methodTagDefaultNames($class);
        foreach ($class->getMethodTags() as $name => $tag) {
            $of = ' of the @method tag of method ' . $name . '()';
            foreach ($tag->getTemplateTags() as $template => $templateTag) {
                $places['template ' . $template . $of] = [$templateTag->getBound(), $templateTag->getDefault()];
            }
            foreach ($tag->getParameters() as $parameterName => $parameter) {
                $places['parameter $' . $parameterName . $of] = [$parameter->getType()];
                $places['the default value of parameter $' . $parameterName . $of]
                    = $defaultNames[$name][$parameterName] ?? [];
            }
            $places['the return type' . $of] = [$tag->getReturnType()];
        }

        foreach ($class->getMixinTags() as $tag) {
            $places['the @mixin tag'][] = $tag->getType();
        }
        foreach ($class->getRequireExtendsTags() as $tag) {
            $places['the @phpstan-require-extends tag'][] = $tag->getType();
        }
        foreach ($class->getRequireImplementsTags() as $tag) {
            $places['the @phpstan-require-implements tag'][] = $tag->getType();
        }
        foreach ($class->getSealedTags() as $tag) {
            $places['the @phpstan-sealed tag'][] = $tag->getType();
        }

        $phpDoc = $class->getResolvedPhpDoc();
        $nameScope = $phpDoc?->getNullableNameScope();
        if ($phpDoc !== null && $nameScope !== null) {
            foreach (array_keys($phpDoc->getTypeAliasTags()) as $alias) {
                $places['type alias ' . $alias] = [
                    $this->typeNodeResolver->resolve(new IdentifierTypeNode($alias), $nameScope),
                ];
            }
            foreach (array_keys($phpDoc->getTypeAliasImportTags()) as $alias) {
                $places['imported type alias ' . $alias] = [
                    $this->typeNodeResolver->resolve(new IdentifierTypeNode($alias), $nameScope),
                ];
            }
        }

        return $places;
    }

    /**
     * lowercase names of the private trait methods the trait uses widen with an alias.
     * an alias without a trait name takes the method from the one trait of the class that has it,
     * whichever trait use it stands in, so every trait of the class is read;
     * PHP refuses such an alias when two traits have the method.
     * an alias with a trait name reads that trait alone:
     * in `use A, B { A::foo insteadof B; B::foo as protected bar; }`, `bar()` is `foo()` of `B`,
     * whatever `A` declares
     *
     * @return array<string, true>
     */
    private function widenedMethods(ClassReflection $class, ClassLike $declaration): array
    {
        $widened = [];
        foreach ($declaration->getTraitUses() as $traitUse) {
            foreach ($traitUse->adaptations as $adaptation) {
                if (
                    !$adaptation instanceof Alias
                    || (($adaptation->newModifier ?? 0) & (Modifiers::PUBLIC | Modifiers::PROTECTED)) === 0
                ) {
                    continue;
                }
                $method = $adaptation->method->toString();
                $traits = $adaptation->trait === null
                    ? array_values($class->getTraits())
                    : ($this->reflectionProvider->hasClass($adaptation->trait->toString())
                        ? [$this->reflectionProvider->getClass($adaptation->trait->toString())]
                        : []);
                foreach ($traits as $trait) {
                    $traitReflection = $trait->getNativeReflection();
                    if ($traitReflection->hasMethod($method) && $traitReflection->getMethod($method)->isPrivate()) {
                        $widened[strtolower($adaptation->newName?->toString() ?? $method)] = true;
                    }
                }
            }
        }

        return $widened;
    }

    /**
     * @param list<Type|string|null> $types a type, or the name of a class an initializer writes
     *
     * @return list<ClassReflection>
     */
    private function internalTypes(array $types): array
    {
        $internal = [];
        foreach ($types as $type) {
            foreach (is_string($type) ? [$type] : ($type?->getReferencedClasses() ?? []) as $name) {
                if (!$this->isChecked($name) || !$this->reflectionProvider->hasClass($name)) {
                    continue;
                }
                $referenced = $this->reflectionProvider->getClass($name);
                if (!$this->isPublicApi($referenced)) {
                    $internal[$referenced->getName()] = $referenced;
                }
            }
        }

        return array_values($internal);
    }

    private function isPublicApi(ClassReflection $class): bool
    {
        $name = $class->getName();
        if (in_array($name, $this->publicClasses, true)) {
            return true;
        }
        if (in_array($name, $this->internalClasses, true)) {
            return false;
        }
        foreach ($this->publicBaseClasses as $base) {
            if ($class->is($base)) {
                return true;
            }
        }
        foreach ($this->publicNamespaces as $namespace) {
            if (str_starts_with($name, $namespace)) {
                return true;
            }
        }

        return false;
    }

    private function isChecked(string $name): bool
    {
        return str_starts_with($name, $this->root) && !str_starts_with($name, $this->excludedRoot);
    }

    /**
     * every name the initializer writes but that of a called function or of a global constant,
     * closure types and bodies included where PHP allows a closure there;
     * PHPStan hands over the expression with its names already resolved
     *
     * @return list<string>
     */
    private static function classNames(Expr $expression): array
    {
        $finder = new NodeFinder();
        $notClasses = [];
        foreach ($finder->findInstanceOf($expression, Expr\FuncCall::class) as $call) {
            if ($call->name instanceof Name) {
                $notClasses[spl_object_id($call->name)] = true;
            }
        }
        foreach ($finder->findInstanceOf($expression, Expr\ConstFetch::class) as $fetch) {
            $notClasses[spl_object_id($fetch->name)] = true;
        }

        $names = [];
        foreach ($finder->findInstanceOf($expression, Name::class) as $name) {
            if (!isset($notClasses[spl_object_id($name)])) {
                $names[] = $name->toString();
            }
        }

        return $names;
    }

    /**
     * keyed by method and parameter name, as `getMethodTags()` keys the tags and their parameters
     *
     * @return array<string, array<string, list<string>>>
     */
    private static function methodTagDefaultNames(ClassReflection $class): array
    {
        $phpDoc = $class->getResolvedPhpDoc();
        $nameScope = $phpDoc?->getNullableNameScope();
        if ($phpDoc === null || $nameScope === null) {
            return [];
        }

        $names = [];
        foreach ($phpDoc->getPhpDocNodes() as $phpDocNode) {
            foreach ($phpDocNode->getTags() as $tag) {
                if (!$tag->value instanceof MethodTagValueNode) {
                    continue;
                }
                foreach ($tag->value->parameters as $parameter) {
                    if ($parameter->defaultValue === null) {
                        continue;
                    }
                    $parameterName = substr($parameter->parameterName, 1);
                    $names[$tag->value->methodName][$parameterName] = [
                        ...$names[$tag->value->methodName][$parameterName] ?? [],
                        ...self::constExprClassNames($parameter->defaultValue, $nameScope),
                    ];
                }
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function constExprClassNames(ConstExprNode $node, NameScope $nameScope): array
    {
        if ($node instanceof ConstExprArrayNode) {
            $names = [];
            foreach ($node->items as $item) {
                if ($item->key !== null) {
                    $names = [...$names, ...self::constExprClassNames($item->key, $nameScope)];
                }
                $names = [...$names, ...self::constExprClassNames($item->value, $nameScope)];
            }

            return $names;
        }

        if (!$node instanceof ConstFetchNode || $node->className === '') {
            return [];
        }

        return [$nameScope->resolveStringName($node->className)];
    }

    /**
     * PHPStan keeps no tag name, only whether the tag lets the property be read and written
     */
    private static function propertyTagName(PropertyTag $tag): string
    {
        return match (true) {
            !$tag->isWritable() => '@property-read',
            !$tag->isReadable() => '@property-write',
            default => '@property',
        };
    }

    private static function kind(ClassReflection $class): string
    {
        return strtolower($class->getClassTypeDescription());
    }
}
