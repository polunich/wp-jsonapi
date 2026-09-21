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
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Store\Accepted;
use Polunich\WpJsonApi\Store\RelationshipAdder;
use Polunich\WpJsonApi\Store\RelationshipRemover;
use Polunich\WpJsonApi\Store\RelationshipReplacer;
use Polunich\WpJsonApi\Store\ResourceCreator;
use Polunich\WpJsonApi\Store\ResourceDeleter;
use Polunich\WpJsonApi\Store\ResourceUpdater;

/**
 * a store that takes every write, records its operation,
 * and returns the one record or the one acceptance it was given
 *
 * @internal
 *
 * @implements ResourceCreator<object>
 * @implements ResourceUpdater<object>
 */
final class RecordingWriter implements
    ResourceCreator,
    ResourceUpdater,
    ResourceDeleter,
    RelationshipReplacer,
    RelationshipAdder,
    RelationshipRemover
{
    /**
     * @var list<object>
     */
    public array $written = [];

    public function __construct(private readonly object $record, private readonly Accepted $accepted) {}

    #[Override]
    public function create(CreateResource $operation): object
    {
        $this->written[] = $operation;

        return $this->record;
    }

    #[Override]
    public function update(UpdateResource $operation): object
    {
        $this->written[] = $operation;

        return $this->record;
    }

    #[Override]
    public function delete(DeleteResource $operation): Accepted
    {
        $this->written[] = $operation;

        return $this->accepted;
    }

    #[Override]
    public function replace(ReplaceRelationship $operation): Accepted
    {
        $this->written[] = $operation;

        return $this->accepted;
    }

    #[Override]
    public function add(AddToRelationship $operation): Accepted
    {
        $this->written[] = $operation;

        return $this->accepted;
    }

    #[Override]
    public function remove(RemoveFromRelationship $operation): Accepted
    {
        $this->written[] = $operation;

        return $this->accepted;
    }
}
