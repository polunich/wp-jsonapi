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
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Node as PhpDocNode;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * reports a class of the global namespace written with a leading backslash,
 * in code and in PHPDoc types, on its line:
 * inside a namespace such a class is imported with `use` and written bare.
 * in a file of the global namespace the bare name already names the class
 * and a `use` of it has no effect, so there the rule asks for the bare name alone.
 *
 * PHP CS Fixer's `global_namespace_import` fixes the code,
 * but in a PHPDoc it reads only the tags of `Annotation::TAGS_WITH_TYPES`,
 * which lack `@template` and PHPStan's own tags such as `@phpstan-assert`;
 * this rule reads every tag, so what the fixer leaves is reported.
 *
 * in code, PHPStan resolves names with `preserveOriginalNames`,
 * so a name written `\Foo` is one whose `originalName` is a `FullyQualified` of one part.
 * global functions and constants, the names of a `use` and the name of a namespace are left alone.
 *
 * in a PHPDoc, each doc comment is parsed with PHPStan's lexer and parser, set to record lines,
 * and a type or the class of a constant fetch written `\Foo` is reported on its line;
 * a class named in the prose of a comment is no type and is left alone
 *
 * @implements Rule<FileNode>
 *
 * @internal
 */
final class QualifiedGlobalNameRule implements Rule
{
    public function __construct(
        private readonly Lexer $lexer,
        private readonly PhpDocParser $phpDocParser,
    ) {}

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
        $names = [];
        $read = [];
        foreach ($node->getNodes() as $statement) {
            $this->walk($statement, false, $names, $read);
        }
        usort($names, static function (array $first, array $second): int {
            return $first[1] <=> $second[1];
        });

        return array_map(static function (array $name): IdentifierRuleError {
            [$class, $line, $namespaced] = $name;

            return RuleErrorBuilder::message(sprintf(
                $namespaced
                    ? 'class %1$s of the global namespace is written \\%1$s; import it with use %1$s; and write %1$s'
                    : 'class %1$s is written \\%1$s in the global namespace, where %1$s names it',
                $class,
            ))->identifier('codeStyle.qualifiedGlobalName')->line($line)->build();
        }, $names);
    }

    /**
     * `$namespaced` tells whether the node lies in a named namespace;
     * a doc comment attached to more than one node is read once
     *
     * @param list<array{string, int, bool}> $names class, line and `$namespaced` of each name
     * @param array<int, true>               $read  start positions of the doc comments read
     */
    private function walk(Node $node, bool $namespaced, array &$names, array &$read): void
    {
        if ($node instanceof Namespace_) {
            $namespaced = $node->name !== null;
        }

        foreach ($node->getComments() as $comment) {
            if ($comment instanceof Doc && !isset($read[$comment->getStartFilePos()])) {
                $read[$comment->getStartFilePos()] = true;
                foreach ($this->documented($comment) as [$class, $line]) {
                    $names[] = [$class, $line, $namespaced];
                }
            }
        }

        if ($node instanceof Use_ || $node instanceof GroupUse) {
            return;
        }

        if ($node instanceof Name) {
            $original = $node->getAttribute('originalName');
            if ($original instanceof FullyQualified && count($original->getParts()) === 1) {
                $names[] = [$original->toString(), $node->getStartLine(), $namespaced];
            }

            return;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            if (
                $subNodeName === 'name'
                && ($node instanceof FuncCall || $node instanceof ConstFetch || $node instanceof Namespace_)
            ) {
                continue;
            }
            $subNode = $node->{$subNodeName};
            foreach (is_array($subNode) ? $subNode : [$subNode] as $child) {
                if ($child instanceof Node) {
                    $this->walk($child, $namespaced, $names, $read);
                }
            }
        }
    }

    /**
     * @return list<array{string, int}>
     */
    private function documented(Doc $comment): array
    {
        $phpDoc = $this->phpDocParser->parse(new TokenIterator($this->lexer->tokenize($comment->getText())));

        $collector = new class extends AbstractNodeVisitor {
            /** @var list<array{string, int}> class and line within the comment of each name */
            public array $names = [];

            #[Override]
            public function enterNode(PhpDocNode $node): ?int
            {
                $name = match (true) {
                    $node instanceof IdentifierTypeNode => $node->name,
                    $node instanceof ConstFetchNode => $node->className,
                    default => '',
                };
                if (preg_match('/^\\\\([^\\\\]+)$/', $name, $class) === 1) {
                    $line = $node->getAttribute(Attribute::START_LINE);
                    $this->names[] = [$class[1], is_int($line) ? $line : 1];
                }

                return null;
            }
        };
        (new NodeTraverser([$collector]))->traverse([$phpDoc]);

        return array_map(static function (array $name) use ($comment): array {
            return [$name[0], $comment->getStartLine() + $name[1] - 1];
        }, $collector->names);
    }
}
