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

namespace Polunich\WpJsonApi\Execution\Result;

use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Http\EntityTag;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;

/**
 * one resource, or `null`, as primary data.
 *
 * the status is 200, or 201 for a finished create, which must carry the created resource.
 * `$resource` is `null` for the related resource of a to-one relationship that points at none,
 * which gets 200 with `data: null` rather than 404;
 * `$type` then names the related type no resource carries
 *
 * @internal
 */
final readonly class ResourceResult implements Result
{
    /**
     * @throws InvalidArgumentException when the status is neither 200 nor 201,
     *                                  or a 201 carries no resource
     */
    public function __construct(
        public string $type,
        public ?ResourceRecord $resource,
        public RecordGraph $graph,
        public SparseFieldsets $fields,
        private int $status,
        private ?EntityTag $entityTag,
    ) {
        if ($status !== 200 && $status !== 201) {
            throw new InvalidArgumentException(sprintf(
                'a document whose primary data is a resource carries the status %d, and the library'
                . ' sends such a document with 200, or 201 for the resource a create made [spec]',
                $status,
            ));
        }

        if ($status === 201 && $resource === null) {
            throw new InvalidArgumentException(
                'a 201 carries no resource, and "the server MUST return a 201 Created response and a'
                . ' document that contains the resource as primary data" [spec, Creating Resources]',
            );
        }
    }

    #[Override]
    public function status(): int
    {
        return $this->status;
    }

    #[Override]
    public function entityTag(): ?EntityTag
    {
        return $this->entityTag;
    }
}
