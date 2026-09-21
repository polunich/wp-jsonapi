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
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * reports every arrow function, a nested one included:
 * the code base writes a closure in full, with its captured variables in `use`.
 * PHP CS Fixer only turns closures into arrow functions (`use_arrow_functions`) and never back,
 * so the rule lives in PHPStan.
 *
 * the rule walks the whole `FileNode` instead of taking `ArrowFunction` nodes:
 * PHPStan never analyses the body of a trait no class uses, so a node rule would miss it
 *
 * @implements Rule<FileNode>
 *
 * @internal
 */
final class ArrowFunctionRule implements Rule
{
    #[Override]
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        return array_values(array_map(static function (ArrowFunction $arrowFunction): IdentifierRuleError {
            return RuleErrorBuilder::message(
                'an arrow function is written; write the closure in full, function with the variables it captures'
                . ' in use',
            )->identifier('codeStyle.arrowFunction')->line($arrowFunction->getStartLine())->build();
        }, (new NodeFinder())->findInstanceOf($node->getNodes(), ArrowFunction::class)));
    }
}
