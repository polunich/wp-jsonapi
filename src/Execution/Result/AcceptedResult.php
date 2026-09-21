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

use Override;
use Polunich\WpJsonApi\Http\EntityTag;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;

/**
 * a 202 for a request accepted and not carried out yet.
 *
 * the primary data is the status monitor, not the resource the request is making,
 * which does not exist yet: a 202 represents the request's status (RFC 9110 15.3.3)
 *
 * @internal
 */
final readonly class AcceptedResult implements Result
{
    /**
     * @param SparseFieldsets $fields applies to the monitor, the one resource object
     */
    public function __construct(
        public ResourceRecord $monitor,
        public RecordGraph $graph,
        public SparseFieldsets $fields,
    ) {}

    #[Override]
    public function status(): int
    {
        return 202;
    }

    #[Override]
    public function entityTag(): ?EntityTag
    {
        return null;
    }
}
