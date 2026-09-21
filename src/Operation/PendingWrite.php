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
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Text\Printable;

/**
 * a write of a single request whose route and query are read and whose content is not yet.
 *
 * the content is read last, once the target is loaded and the permission and preconditions hold:
 * RFC 9110 evaluates preconditions just before the request content is processed.
 * this value keeps what was read up to then, so nothing is read twice
 *
 * @internal
 */
final readonly class PendingWrite
{
    /**
     * @param ?Identifier   $target          null for a create, else the target or the parent
     * @param Values<mixed> $queryParameters
     *
     * @throws InvalidArgumentException when the kind carries no content, the type is empty,
     *                                  the target does not match the kind or the type,
     *                                  or the relationship does not match the kind or is empty
     */
    public function __construct(
        public OperationKind $kind,
        public string $type,
        public ?Identifier $target,
        public ?string $relationship,
        public ?IncludePaths $include,
        public SparseFieldsets $fields,
        public LanguagePreferences $languages,
        public Values $queryParameters,
    ) {
        if (!$kind->carriesContent()) {
            throw new InvalidArgumentException(sprintf(
                'a %s request carries no content, and its operation is built without one',
                $kind->value,
            ));
        }

        if ($type === '') {
            throw new InvalidArgumentException('a write addresses a resource type, and a type is never empty');
        }

        if (($target !== null) !== $kind->actsOnExistingResource()) {
            throw new InvalidArgumentException(sprintf(
                'the %s write %s, and this one names %s',
                $kind->value,
                $kind->actsOnExistingResource() ? 'acts on an existing resource' : 'acts on no existing resource',
                $target === null ? 'none' : 'one',
            ));
        }

        if ($target !== null && $target->type !== $type) {
            throw new InvalidArgumentException(sprintf(
                'a write of type "%s" names a target of type "%s", and a route addresses a resource of its own type',
                Printable::of($type),
                Printable::of($target->type),
            ));
        }

        if (($relationship !== null) !== $kind->targetsRelationship()) {
            throw new InvalidArgumentException(sprintf(
                'the %s write %s, and this one names %s',
                $kind->value,
                $kind->targetsRelationship() ? 'names a relationship' : 'names no relationship',
                $relationship === null ? 'none' : 'one',
            ));
        }

        if ($relationship === '') {
            throw new InvalidArgumentException('a relationship write names its relationship, whose name is never'
                . ' empty');
        }
    }
}
