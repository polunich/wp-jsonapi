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

namespace Polunich\WpJsonApi\Execution;

use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\RecordLinkage;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;

/**
 * what one include walk has gathered: the records it holds,
 * the linkage it built and the resources `included` carries.
 * a type and id pair keeps the first record read for it and is rendered once,
 * as primary data or in `included`.
 * it is mutable on purpose: the walk adds to it level by level
 *
 * @internal
 */
final class IncludeWalk
{
    /**
     * @var array<array-key, array<array-key, ResourceRecord>> type => id => record
     */
    private array $known = [];

    /**
     * @var array<array-key, array<array-key, true>> type => id => whether the pair is rendered
     */
    private array $rendered = [];

    /**
     * @var list<ResourceRecord>
     */
    private array $included = [];

    /**
     * @var array<array-key, array<array-key, array<array-key, RecordLinkage>>>
     *      type => id => relationship => linkage
     */
    private array $linkage = [];

    /**
     * the record held for the pair of `$resource`, which becomes it where none is held yet
     */
    public function know(ResourceRecord $resource): ResourceRecord
    {
        return $this->known[$resource->type][$resource->id] ??= $resource;
    }

    public function known(string $type, string $id): ?ResourceRecord
    {
        return $this->known[$type][$id] ?? null;
    }

    /**
     * a pair of the primary data: rendered, and never in `included`
     */
    public function renderAsPrimary(ResourceRecord $resource): void
    {
        $this->rendered[$resource->type][$resource->id] = true;
    }

    public function include(ResourceRecord $resource): void
    {
        if (isset($this->rendered[$resource->type][$resource->id])) {
            return;
        }

        $this->rendered[$resource->type][$resource->id] = true;
        $this->included[] = $resource;
    }

    public function isRendered(ResourceRecord $resource): bool
    {
        return isset($this->rendered[$resource->type][$resource->id]);
    }

    public function link(string $type, string $id, string $relationship, RecordLinkage $linkage): void
    {
        $this->linkage[$type][$id][$relationship] = $linkage;
    }

    public function graph(bool $includeSupplied): RecordGraph
    {
        return new RecordGraph($includeSupplied, $this->included, $this->linkage);
    }
}
