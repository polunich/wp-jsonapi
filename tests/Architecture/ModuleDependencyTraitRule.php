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
use PHPat\Parser\BuiltInClasses;
use PHPat\Rule\Assertion\Constraint;
use PHPat\Selector\SelectorInterface;
use PHPat\Statement\Statement;
use PHPat\Statement\StatementBuilder;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\FileTypeMapper;

/**
 * applies the dependency rules of `ModuleDependencyRules` to each trait declaration,
 * against the module the trait lies in.
 * PHPat takes the subject from `Scope::getClassReflection()`,
 * and PHPStan analyses a trait body only in the scope of a class that uses it,
 * so PHPat alone checks a trait against the module of its user and never a trait no class uses.
 *
 * the rule takes `Trait_`, which PHPStan emits once for each trait of an analysed file,
 * used or not, and decides as `RelationAssertion` does for a class:
 * a can-only rule reports a name no target matches, a should-not rule one a target matches.
 *
 * the names read are those the PHPat extractors read for a class:
 * - the traits it uses;
 * - the attributes of the trait, of its members and of closure or arrow function parameters,
 *   but not those of a closure or an arrow function itself, which PHPStan never passes to a rule;
 * - the native types of properties and parameters, and the native return types of methods;
 * - the class of a constant fetch, `new`, `catch`, `instanceof` and a static call,
 *   except a first-class callable, which PHPStan hands to the rules as another node;
 * - unless doc comments are ignored, `@method`, `@mixin` and `@property` of the trait,
 *   `@param`, `@return` and `@throws` of a method and `@var` of an expression statement,
 *   resolved in the name scope of the trait and of the method.
 * a trait has no parent and no interface, so the extractors of those have nothing to read.
 *
 * a name is reported on the line of the node that writes it,
 * and a tag of the trait or of a method on the line of that declaration,
 * which starts at its attribute when it carries one, as in PHPat.
 * a name is reported once per line and rule, where PHPat reports it once per matching target;
 * the should-not targets of `ModuleDependencyRules` are disjoint, so the two agree.
 *
 * `self`, `static`, `parent` and the trait itself are skipped:
 * the first three name the using class or its parent, each checked on its own.
 * a class declared in the body is skipped with its body: only an anonymous class can stand there,
 * and PHPat takes it as a subject without a namespace, which no module selector matches.
 *
 * where a class of the trait's own module uses the trait, PHPat still reports the body
 * under the class name, so such a name is reported for the trait and again for each such class
 *
 * @implements Rule<Trait_>
 *
 * @internal
 */
final class ModuleDependencyTraitRule implements Rule
{
    /**
     * @param list<Statement> $statements    can-only and should-not depend statements
     * @param Configuration   $configuration read for `ignore_built_in_classes`,
     *                                       `ignore_doc_comments` and `show_rule_names`
     *
     * @throws InvalidArgumentException for a should-depend statement
     */
    public function __construct(
        private readonly array $statements,
        private readonly Configuration $configuration,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly FileTypeMapper $fileTypeMapper,
    ) {
        foreach ($statements as $statement) {
            if ($statement->constraint === Constraint::Should) {
                throw new InvalidArgumentException(sprintf(
                    'a rule should depend on has no reading on a trait, since PHPat applies it to each node of a'
                    . ' class, got the rule "%s"',
                    $statement->ruleName,
                ));
            }
        }
    }

    public static function forLibrary(
        StatementBuilder $statementBuilder,
        Configuration $configuration,
        ReflectionProvider $reflectionProvider,
        FileTypeMapper $fileTypeMapper,
    ): self {
        return new self(
            array_values($statementBuilder->build('depend')),
            $configuration,
            $reflectionProvider,
            $fileTypeMapper,
        );
    }

    #[Override]
    public function getNodeType(): string
    {
        return Trait_::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $name = $node->namespacedName;
        if ($name === null || !$this->reflectionProvider->hasClass($name->toString())) {
            return [];
        }
        $trait = $this->reflectionProvider->getClass($name->toString());

        $statements = array_values(array_filter(
            $this->statements,
            static function (Statement $statement) use ($trait): bool {
                return self::selects($statement, $trait);
            },
        ));
        if ($statements === []) {
            return [];
        }

        $names = [];
        foreach ($this->names($node, $trait, $scope) as [$written, $line]) {
            if (
                strtolower($written) === strtolower($trait->getName())
                || ($this->configuration->ignoreBuiltInClasses() && $this->isBuiltIn($written))
            ) {
                continue;
            }
            $names[$written . "\n" . $line] = [$written, $line];
        }

        $errors = [];
        foreach ($statements as $statement) {
            foreach ($names as [$written, $line]) {
                $matches = false;
                foreach ($statement->targets as $target) {
                    $matches = $matches || $this->matches($written, $target, $statement->targetExcludes);
                }
                if ($matches === ($statement->constraint === Constraint::CanOnly)) {
                    continue;
                }

                $message = sprintf(
                    'trait %s should not depend on %s, which the module dependency rules forbid',
                    $trait->getName(),
                    $written,
                );
                $error = RuleErrorBuilder::message(
                    $this->configuration->showRuleNames() ? $statement->ruleName . ': ' . $message : $message,
                )->identifier('phpat.' . $statement->ruleName)->line($line);
                foreach ($statement->tips as $tip) {
                    $error->addTip($tip);
                }
                if ($statement->nonIgnorable) {
                    $error->nonIgnorable();
                }
                $errors[] = $error->build();
            }
        }

        return $errors;
    }

    /**
     * mirrors `RelationAssertion::validateGetErrors()` for a class
     */
    private static function selects(Statement $statement, ClassReflection $trait): bool
    {
        if ($trait->isBuiltin() || !$statement->subject->matches($trait)) {
            return false;
        }
        foreach ($statement->subjectExcludes as $exclude) {
            if ($exclude->matches($trait)) {
                return false;
            }
        }

        return true;
    }

    /**
     * mirrors `RelationAssertion::nodeMatchesTarget()`
     *
     * @param array<SelectorInterface> $excludes
     */
    private function matches(string $class, SelectorInterface $target, array $excludes): bool
    {
        if (!$this->reflectionProvider->hasClass($class)) {
            return false;
        }
        $reflection = $this->reflectionProvider->getClass($class);
        if (!$target->matches($reflection)) {
            return false;
        }
        foreach ($excludes as $exclude) {
            if ($exclude->matches($reflection)) {
                return false;
            }
        }

        return true;
    }

    /**
     * mirrors `RelationAssertion::isBuiltInClass()`
     */
    private function isBuiltIn(string $class): bool
    {
        return in_array($class, BuiltInClasses::PHP_BUILT_IN_CLASSES, true)
            || ($this->reflectionProvider->hasClass(
                $class,
            ) && $this->reflectionProvider->getClass($class)->isBuiltin());
    }

    /**
     * @return list<array{string, int}>
     */
    private function names(Trait_ $node, ClassReflection $trait, Scope $scope): array
    {
        $names = [];
        foreach ($node->getTraitUses() as $traitUse) {
            self::write($names, $traitUse->traits, $scope, $traitUse->getStartLine());
        }

        $docComment = $node->getDocComment();
        if ($docComment !== null && !$this->configuration->ignoreDocComments()) {
            $phpDoc = $this->fileTypeMapper->getResolvedPhpDoc(
                $scope->getFile(),
                $trait->getName(),
                null,
                null,
                $docComment->getText(),
            );
            $types = [];
            foreach ($phpDoc->getMethodTags() as $tag) {
                foreach ($tag->getParameters() as $parameter) {
                    $types[] = $parameter->getType();
                }
                $types[] = $tag->getReturnType();
            }
            foreach ($phpDoc->getMixinTags() as $tag) {
                $types[] = $tag->getType();
            }
            foreach ($phpDoc->getPropertyTags() as $tag) {
                if ($tag->isReadable()) {
                    $types[] = $tag->getReadableType();
                }
                if ($tag->isWritable()) {
                    $types[] = $tag->getWritableType();
                }
            }
            foreach ($types as $type) {
                foreach ($type->getReferencedClasses() as $referenced) {
                    $names[] = [$referenced, $node->getStartLine()];
                }
            }
        }

        foreach ([...$node->attrGroups, ...$node->stmts] as $child) {
            $this->walk($names, $child, $trait, $scope, null);
        }

        return $names;
    }

    /**
     * `$method` names the enclosing method, whose name scope resolves a `@var` tag
     *
     * @param list<array{string, int}> $names
     */
    private function walk(array &$names, Node $node, ClassReflection $trait, Scope $scope, ?string $method): void
    {
        if ($node instanceof Stmt\ClassLike) {
            return;
        }

        $line = $node->getStartLine();
        match (true) {
            $node instanceof Node\AttributeGroup => self::write(
                $names,
                array_map(static function (Node\Attribute $attribute): Name {
                    return $attribute->name;
                }, $node->attrs),
                $scope,
                $line,
            ),
            $node instanceof Stmt\Property, $node instanceof Node\Param => self::write(
                $names,
                self::typeNames($node->type),
                $scope,
                $line,
            ),
            $node instanceof Expr\ClassConstFetch,
            $node instanceof Expr\New_,
            $node instanceof Expr\StaticCall && !$node->isFirstClassCallable(),
            $node instanceof Expr\Instanceof_ => self::write(
                $names,
                $node->class instanceof Name ? [$node->class] : [],
                $scope,
                $line,
            ),
            $node instanceof Stmt\Catch_ => self::write($names, $node->types, $scope, $line),
            $node instanceof Stmt\ClassMethod => $this->writeMethod($names, $node, $trait, $scope),
            $node instanceof Stmt\Expression => $this->writeVarTags($names, $node, $trait, $scope, $method),
            default => null,
        };

        if ($node instanceof Stmt\ClassMethod) {
            $method = $node->name->toString();
        }
        foreach ($node->getSubNodeNames() as $subNodeName) {
            if (
                $subNodeName === 'attrGroups'
                && ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction)
            ) {
                continue;
            }
            $subNode = $node->{$subNodeName};
            foreach (is_array($subNode) ? $subNode : [$subNode] as $child) {
                if ($child instanceof Node) {
                    $this->walk($names, $child, $trait, $scope, $method);
                }
            }
        }
    }

    /**
     * @param list<array{string, int}> $names
     */
    private function writeMethod(array &$names, Stmt\ClassMethod $method, ClassReflection $trait, Scope $scope): void
    {
        self::write($names, self::typeNames($method->returnType), $scope, $method->getStartLine());
        $docComment = $method->getDocComment();
        if ($docComment === null || $this->configuration->ignoreDocComments()) {
            return;
        }

        $phpDoc = $this->fileTypeMapper->getResolvedPhpDoc(
            $scope->getFile(),
            $trait->getName(),
            null,
            $method->name->toString(),
            $docComment->getText(),
        );
        $types = [];
        foreach ($phpDoc->getParamTags() as $tag) {
            $types[] = $tag->getType();
        }
        $types[] = $phpDoc->getReturnTag()?->getType();
        $types[] = $phpDoc->getThrowsTag()?->getType();
        foreach ($types as $type) {
            foreach ($type?->getReferencedClasses() ?? [] as $referenced) {
                $names[] = [$referenced, $method->getStartLine()];
            }
        }
    }

    /**
     * @param list<array{string, int}> $names
     */
    private function writeVarTags(
        array &$names,
        Stmt\Expression $expression,
        ClassReflection $trait,
        Scope $scope,
        ?string $method,
    ): void {
        $docComment = $expression->getDocComment();
        if ($docComment === null || $this->configuration->ignoreDocComments()) {
            return;
        }

        $phpDoc = $this->fileTypeMapper->getResolvedPhpDoc(
            $scope->getFile(),
            $trait->getName(),
            null,
            $method,
            $docComment->getText(),
        );
        foreach ($phpDoc->getVarTags() as $tag) {
            foreach ($tag->getType()->getReferencedClasses() as $referenced) {
                $names[] = [$referenced, $expression->getStartLine()];
            }
        }
    }

    /**
     * resolves a name as PHPat's `TypeNodeParser::parseName()` does
     *
     * @param list<array{string, int}> $names
     * @param array<Name>              $written
     */
    private static function write(array &$names, array $written, Scope $scope, int $line): void
    {
        foreach ($written as $name) {
            if (!$name->isSpecialClassName()) {
                $names[] = [$name->isFullyQualified() ? $name->toString() : $scope->resolveName($name), $line];
            }
        }
    }

    /**
     * flattens a native type as PHPat's `TypeNodeParser::parse()` does
     *
     * @return list<Name>
     */
    private static function typeNames(?Node $type): array
    {
        return match (true) {
            $type instanceof Name => [$type],
            $type instanceof NullableType => self::typeNames($type->type),
            $type instanceof UnionType, $type instanceof IntersectionType => array_merge(
                ...array_map(static function (Node $member): array {
                    return self::typeNames($member);
                }, $type->types),
            ),
            default => [],
        };
    }
}
