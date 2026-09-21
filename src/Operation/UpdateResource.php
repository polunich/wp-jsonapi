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

use Override;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;

/**
 * the update of a resource: only the members the request carries change,
 * and each relationship it names is replaced whole by its linkage
 */
final readonly class UpdateResource implements ConditionalWrite
{
    public string $type;

    /**
     * @param Values<mixed>   $attributes
     * @param Values<Linkage> $relationships
     * @param Values<mixed>   $queryParameters
     */
    public function __construct(
        public Identifier $target,
        public Values $attributes,
        public Values $relationships,
        public ?Precondition $precondition,
        public ?IncludePaths $include,
        public SparseFieldsets $fields,
        public LanguagePreferences $languages,
        public Values $queryParameters,
    ) {
        $this->type = $target->type;
    }

    #[Override]
    public function kind(): OperationKind
    {
        return OperationKind::UpdateResource;
    }

    #[Override]
    public function type(): string
    {
        return $this->type;
    }

    #[Override]
    public function relationship(): ?string
    {
        return null;
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
            $this->target,
            $this->attributes,
            $this->relationships,
            $precondition,
            $this->include,
            $this->fields,
            $this->languages,
            $this->queryParameters,
        );
    }
}
