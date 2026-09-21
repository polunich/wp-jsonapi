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

namespace Polunich\WpJsonApi\Tests\Fixtures\Store;

use Override;
use Polunich\WpJsonApi\Store\CollectionQuery;
use Polunich\WpJsonApi\Store\CollectionReader;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedLoader;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Store\Slice;

/**
 * a store of fixed records that serves every reading from them and records each request
 *
 * @internal
 *
 * @implements CollectionReader<object>
 * @implements RelatedLoader<object>
 * @implements ResourceLoader<object>
 */
final class RecordingStore implements CollectionReader, RelatedLoader, ResourceLoader
{
    /**
     * @var list<CollectionQuery>
     */
    public array $queries = [];

    /**
     * @var list<LoadRequest>
     */
    public array $loads = [];

    /**
     * @var list<RelatedLoadRequest>
     */
    public array $relations = [];

    /**
     * @param list<object>                                     $slice
     * @param array<array-key, array<array-key, object>>       $records type => id => record
     * @param array<array-key, array<array-key, list<object>>> $members relationship =>
     *                                                                  parent id => records
     */
    public function __construct(
        private readonly array $slice = [],
        private readonly array $records = [],
        private readonly array $members = [],
        private readonly ?int $total = null,
        private readonly ?int $estimatedTotal = null,
        private readonly bool $honoursLimit = true,
    ) {}

    /**
     * @return Slice<object>
     */
    #[Override]
    public function read(CollectionQuery $query): Slice
    {
        $this->queries[] = $query;

        return new Slice($this->slice, $this->total, $this->estimatedTotal);
    }

    /**
     * @return list<object|null>
     */
    #[Override]
    public function load(LoadRequest $request): array
    {
        $this->loads[] = $request;
        $loaded = [];

        foreach ($request->ids as $id) {
            $loaded[] = $this->records[$request->type][$id] ?? null;
        }

        return $loaded;
    }

    /**
     * @return list<list<object>>
     */
    #[Override]
    public function loadRelated(RelatedLoadRequest $request): array
    {
        $this->relations[] = $request;
        $related = [];

        foreach ($request->parentIds as $parentId) {
            $found = $this->members[$request->relationship][$parentId] ?? [];
            $related[] = $this->honoursLimit ? array_slice($found, 0, $request->limit) : $found;
        }

        return $related;
    }
}
