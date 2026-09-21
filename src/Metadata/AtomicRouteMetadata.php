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

namespace Polunich\WpJsonApi\Metadata;

use InvalidArgumentException;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Routing\PathTemplate;
use Polunich\WpJsonApi\Text\Printable;

/**
 * one route of the Atomic Operations extension a project declares, resolved against the types.
 *
 * the name is the operationId of the contract, under the rule of `OperationName`.
 * the path holds literal segments alone: an operation names its target in the document,
 * by `ref` or by its `data`, so a path parameter would carry nothing an operation reads.
 * `$types` are the types an operation of this route may target, in declaration order;
 * an operation that targets another type gets 409, as a POST whose type lies
 * outside "the collection represented by the endpoint" is (JSON:API 1.1, Creating Resources)
 *
 * @internal
 */
final readonly class AtomicRouteMetadata
{
    /** @var non-empty-list<string> */
    public array $types;

    /**
     * @param list<string>          $types
     * @param ?list<SecurityScheme> $securitySchemes in place of the API's, null for those
     *
     * @throws InvalidArgumentException when the name breaks the rule of `OperationName`,
     *                                  the path holds a parameter,
     *                                  the types are empty or name a type twice,
     *                                  a security scheme is named twice,
     *                                  or the tag or the description is empty
     */
    public function __construct(
        public string $name,
        public PathTemplate $template,
        array $types,
        public ?string $tag,
        public ?string $description,
        public ?array $securitySchemes = null,
    ) {
        OperationName::check($name, 'an atomic route');

        if ($template->parameters() !== []) {
            throw new InvalidArgumentException(sprintf(
                'the atomic route "%s" lies at "%s", which holds a parameter, and an atomic route takes none:'
                . ' each operation names its target in the document',
                $name,
                $template->path,
            ));
        }

        if ($types === []) {
            throw new InvalidArgumentException(sprintf(
                'the atomic route "%s" takes no type, so no operation could run on it',
                $name,
            ));
        }

        foreach ($types as $position => $type) {
            if (array_search($type, $types, true) !== $position) {
                throw new InvalidArgumentException(sprintf(
                    'the atomic route "%s" names type "%s" twice',
                    $name,
                    Printable::of($type),
                ));
            }
        }

        SecuritySchemes::checkDistinct($securitySchemes ?? [], sprintf('the atomic route "%s"', $name));
        DeclaredText::check($tag, sprintf('the atomic route "%s"', $name), 'tag');
        DeclaredText::check($description, sprintf('the atomic route "%s"', $name), 'description');

        $this->types = $types;
    }

    public function accepts(string $type): bool
    {
        return in_array($type, $this->types, true);
    }
}
