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
use Override;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\GraphRequest;
use Polunich\WpJsonApi\Execution\IncludeLoader;
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Store\Accepted;
use Polunich\WpJsonApi\Store\ConstraintViolationException;
use Polunich\WpJsonApi\Store\RelatedResourceNotFoundException;
use Polunich\WpJsonApi\Store\ResourceNotFoundException;
use Polunich\WpJsonApi\Store\VersionMismatchException;
use UnexpectedValueException;

/**
 * a finished update always gets 200 with the updated resource and the tag read off it:
 * the library cannot tell whether the store changed more than asked, which requires the 200.
 * the precondition travels on the operation for the store to evaluate; it is not read here.
 * an `Accepted` gets 202
 *
 * @internal
 */
final readonly class UpdateResourceProcessor implements OperationProcessor
{
    public function __construct(
        private ResourceRegistry $resources,
        private StoreGateway $store,
        private IncludeLoader $includes,
        private Preconditions $preconditions,
        private AcceptedWrites $accepted,
    ) {}

    /**
     * @throws LogicException                   when the operation is of another kind,
     *                                          the type binds no resource updater,
     *                                          or a capability the include walk reads is not bound
     * @throws UnexpectedValueException         when the id accessor returns no non-empty string,
     *                                          a loader breaks its batch contract,
     *                                          or the store returns an undeclared monitor type
     * @throws OutOfBoundsException             when a type or a relationship is not declared
     * @throws ResourceNotFoundException        as the store reported it
     * @throws RelatedResourceNotFoundException as the store reported it
     * @throws ConstraintViolationException     as the store reported it
     * @throws VersionMismatchException         as the store reported it
     */
    #[Override]
    public function process(Operation $operation, ExecutionContext $context): AcceptedResult|ResourceResult
    {
        if (!$operation instanceof UpdateResource) {
            throw new LogicException(sprintf(
                'an update_resource processor processes an update_resource operation, and this one is %s',
                $operation->kind()->value,
            ));
        }

        $type = $operation->target->type;
        $updated = $this->store->update($operation);

        if ($updated instanceof Accepted) {
            return $this->accepted->result($operation, $updated, $operation->fields, $context);
        }

        $record = ResourceRecord::of($this->resources->get($type), $updated);

        return new ResourceResult(
            $type,
            $record,
            $this->includes->load(GraphRequest::of($operation), [$record], $context),
            $operation->fields,
            200,
            $this->preconditions->entityTag($type, $updated, $context),
        );
    }
}
