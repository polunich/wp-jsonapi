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
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\FileTypeMapper;

/**
 * runs `PublicApiCheck` on each trait declaration.
 * the rule takes `Trait_`, which PHPStan emits once per trait of an analysed file, used or not;
 * `InTraitNode` comes once per using class instead, and never for a trait no class uses.
 * the shape is that of PHPStan's own `MethodTagTraitRule`: a check and the reflection provider,
 * which reads the trait by the namespaced name of the node
 *
 * @implements Rule<Trait_>
 *
 * @internal
 */
final class PublicApiTraitRule implements Rule
{
    public function __construct(
        private readonly PublicApiCheck $check,
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public static function forLibrary(
        ReflectionProvider $reflectionProvider,
        FileTypeMapper $fileTypeMapper,
        TypeNodeResolver $typeNodeResolver,
    ): self {
        return new self(
            PublicApiCheck::forLibrary($reflectionProvider, $fileTypeMapper, $typeNodeResolver),
            $reflectionProvider,
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

        return $this->check->check($this->reflectionProvider->getClass($name->toString()), $node, $scope);
    }
}
