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
 * the lid names the resource within the request document and is no input of the write,
 * so `AtomicOperation::$lid` carries it
 */
final readonly class CreateResource implements Operation
{
    /**
     * @param ?string         $id              client-generated, null when the server assigns one
     * @param Values<mixed>   $attributes
     * @param Values<Linkage> $relationships
     * @param Values<mixed>   $queryParameters
     *
     * @throws InvalidArgumentException when the type is empty, or the id given is empty
     */
    public function __construct(
        public string $type,
        public ?string $id,
        public Values $attributes,
        public Values $relationships,
        public ?IncludePaths $include,
        public SparseFieldsets $fields,
        public LanguagePreferences $languages,
        public Values $queryParameters,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('a resource is created with a type, and a type is never empty');
        }

        if ($id === '') {
            throw new InvalidArgumentException(sprintf(
                'a resource of type "%s" is created with an empty id, which identifies no resource',
                Printable::of($type),
            ));
        }
    }

    #[Override]
    public function kind(): OperationKind
    {
        return OperationKind::CreateResource;
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
}
