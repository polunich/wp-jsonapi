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

namespace Polunich\WpJsonApi\Execution\Processor;

use LogicException;
use OutOfBoundsException;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\IncludeLoader;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Store\Accepted;
use Polunich\WpJsonApi\Text\Printable;
use UnexpectedValueException;

/**
 * the 202 of a write the store accepted without carrying it out, the same for every write kind.
 *
 * the monitor must be of the type the resource declares for the write kind:
 * an `Accepted` of another type, or for a kind that declares none, is a project bug,
 * and the library responds with 500
 *
 * @internal
 */
final readonly class AcceptedWrites
{
    public function __construct(
        private ResourceRegistry $resources,
        private IncludeLoader $includes,
    ) {}

    /**
     * @param (
     *     CreateResource
     *     |UpdateResource
     *     |DeleteResource
     *     |ReplaceRelationship
     *     |AddToRelationship
     *     |RemoveFromRelationship
     * ) $operation
     *
     * @throws UnexpectedValueException when the store returns an undeclared monitor type,
     *                                  the id accessor returns no non-empty string,
     *                                  or a loader breaks its batch contract
     * @throws LogicException           when a capability the walk reads is not bound
     * @throws OutOfBoundsException     when a type is not declared
     */
    public function result(
        CreateResource
        |UpdateResource
        |DeleteResource
        |ReplaceRelationship
        |AddToRelationship
        |RemoveFromRelationship $operation,
        Accepted $accepted,
        SparseFieldsets $fields,
        ExecutionContext $context,
    ): AcceptedResult {
        $resource = $this->resources->get($operation->type());
        $monitorType = $resource->monitorTypes[$operation->kind()->value] ?? null;

        if ($monitorType === null || $monitorType !== $accepted->type) {
            throw new UnexpectedValueException(sprintf(
                'the store accepted the %s of type "%s" with a monitor of type "%s", and the declaration names %s'
                . ' for that kind',
                $operation->kind()->value,
                Printable::of($resource->type),
                Printable::of($accepted->type),
                $monitorType === null
                    ? 'no monitor type'
                    : sprintf('the monitor type "%s"', Printable::of($monitorType)),
            ));
        }

        $monitor = ResourceRecord::of($this->resources->get($accepted->type), $accepted->record);

        return new AcceptedResult(
            $monitor,
            $this->includes->loadMonitor($operation->languages, $monitor, $context),
            $fields,
        );
    }
}
