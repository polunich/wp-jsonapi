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

namespace Polunich\WpJsonApi\Operation;

use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the addition of members to a to-many relationship of the parent;
 * the spec updates a to-one relationship with `PATCH` alone. an empty linkage adds nothing.
 *
 * the linkage may name members already related, which the spec forbids adding again
 */
final readonly class AddToRelationship implements ConditionalWrite
{
    public string $type;

    /**
     * @param Values<mixed> $queryParameters
     *
     * @throws InvalidArgumentException when the relationship name is empty, the linkage is to-one,
     *                                  or an include path does not start with the relationship
     */
    public function __construct(
        public Identifier $parent,
        public string $relationship,
        public Linkage $linkage,
        public ?Precondition $precondition,
        public ?IncludePaths $include,
        public SparseFieldsets $fields,
        public LanguagePreferences $languages,
        public Values $queryParameters,
    ) {
        if ($relationship === '') {
            throw new InvalidArgumentException(sprintf(
                'members are added to a relationship of a parent of type "%s" by its name, which is never empty',
                Printable::of($parent->type),
            ));
        }

        if (!$linkage->isToMany()) {
            throw new InvalidArgumentException(sprintf(
                'members are added to a to-many relationship only, and the linkage of relationship "%s" is to-one',
                Printable::of($relationship),
            ));
        }

        $include?->checkRootedAt($relationship, $parent->type);

        $this->type = $parent->type;
    }

    #[Override]
    public function kind(): OperationKind
    {
        return OperationKind::AddToRelationship;
    }

    #[Override]
    public function type(): string
    {
        return $this->type;
    }

    #[Override]
    public function relationship(): string
    {
        return $this->relationship;
    }

    #[Override]
    public function precondition(): ?Precondition
    {
        return $this->precondition;
    }

    #[Override]
    public function withPrecondition(Precondition $precondition): static
    {
        return new self(
            $this->parent,
            $this->relationship,
            $this->linkage,
            $precondition,
            $this->include,
            $this->fields,
            $this->languages,
            $this->queryParameters,
        );
    }
}
