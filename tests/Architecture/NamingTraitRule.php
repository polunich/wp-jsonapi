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
use PHPat\Statement\Statement;
use PHPat\Statement\StatementBuilder;
use PhpParser\Node;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * applies the naming rules of `NamingRules` to each trait declaration:
 * PHPat checks a name through `ClassnameRule` on `InClassNode`, which PHPStan emits for no trait,
 * so PHPat alone never checks the name of a trait, whether a class uses it or not.
 *
 * the rule takes `Trait_`, which PHPStan emits once for each trait of an analysed file,
 * used or not, and decides as `DeclarationAssertion` and `ClassnameExtractor` do for a class.
 *
 * of the rules of `NamingRules`, only the trait suffix and the reserved words select a trait:
 * a trait implements no interface, and `ClassReflection::isAbstract()` is false for a trait
 * even with an abstract method, where PHP's `ReflectionClass::isAbstract()` is true;
 * the prefix rule is about abstract classes anyway
 *
 * @implements Rule<Trait_>
 *
 * @internal
 */
final class NamingTraitRule implements Rule
{
    /**
     * @param list<Statement> $statements    should-be-named statements
     * @param Configuration   $configuration read for `show_rule_names`
     */
    public function __construct(
        private readonly array $statements,
        private readonly Configuration $configuration,
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public static function forLibrary(
        StatementBuilder $statementBuilder,
        Configuration $configuration,
        ReflectionProvider $reflectionProvider,
    ): self {
        return new self(array_values($statementBuilder->build('beNamed')), $configuration, $reflectionProvider);
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

        $errors = [];
        foreach ($this->statements as $statement) {
            if (!self::selects($statement, $trait)) {
                continue;
            }

            $meets = self::meets($statement, $trait->getName());
            $breaks = match ($statement->constraint) {
                Constraint::Should => !$meets,
                Constraint::ShouldNot => $meets,
                Constraint::CanOnly => false,
            };
            if (!$breaks) {
                continue;
            }

            $classname = $statement->params['classname'] ?? null;
            $message = sprintf(
                ($statement->params['isRegex'] ?? null) === true
                    ? 'trait %s should%s be named matching the regex %s'
                    : 'trait %s should%s be named %s',
                $trait->getName(),
                $statement->constraint === Constraint::ShouldNot ? ' not' : '',
                is_string($classname) ? $classname : '',
            );
            $error = RuleErrorBuilder::message(
                $this->configuration->showRuleNames() ? $statement->ruleName . ': ' . $message : $message,
            )->identifier('phpat.' . $statement->ruleName);
            foreach ($statement->tips as $tip) {
                $error->addTip($tip);
            }
            if ($statement->nonIgnorable) {
                $error->nonIgnorable();
            }
            $errors[] = $error->build();
        }

        return $errors;
    }

    /**
     * mirrors `DeclarationAssertion::processNode()` for a class
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
     * mirrors `ClassnameExtractor::meetsDeclaration()`, whose `trimSeparators()` trims backslashes
     */
    private static function meets(Statement $statement, string $name): bool
    {
        $classname = $statement->params['classname'] ?? null;
        if (!is_string($classname) || !isset($statement->params['isRegex'])) {
            return false;
        }

        return $statement->params['isRegex'] === true
            ? preg_match($classname, $name) === 1
            : $name === trim($classname, '\\');
    }
}
