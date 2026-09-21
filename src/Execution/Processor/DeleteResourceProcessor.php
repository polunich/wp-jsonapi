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
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Store\ConstraintViolationException;
use Polunich\WpJsonApi\Store\ResourceNotFoundException;
use Polunich\WpJsonApi\Store\VersionMismatchException;
use UnexpectedValueException;

/**
 * a finished delete gets 204 with no document, so nothing is read back.
 * an `Accepted` gets 202 with empty fieldsets, since a delete carries none
 *
 * @internal
 */
final readonly class DeleteResourceProcessor implements OperationProcessor
{
    public function __construct(
        private StoreGateway $store,
        private AcceptedWrites $accepted,
    ) {}

    /**
     * @throws LogicException               when the operation is of another kind,
     *                                      the type binds no resource deleter,
     *                                      or a capability the monitor walk reads is not bound
     * @throws UnexpectedValueException     when the store returns an undeclared monitor type,
     *                                      the id accessor returns no non-empty string,
     *                                      or a loader breaks its batch contract
     * @throws OutOfBoundsException         when a type or a relationship is not declared
     * @throws ResourceNotFoundException    as the store reported it
     * @throws ConstraintViolationException as the store reported it
     * @throws VersionMismatchException     as the store reported it
     */
    #[Override]
    public function process(Operation $operation, ExecutionContext $context): AcceptedResult|NoContentResult
    {
        if (!$operation instanceof DeleteResource) {
            throw new LogicException(sprintf(
                'a delete_resource processor processes a delete_resource operation, and this one is %s',
                $operation->kind()->value,
            ));
        }

        $accepted = $this->store->delete($operation);

        if ($accepted === null) {
            return new NoContentResult();
        }

        return $this->accepted->result($operation, $accepted, new SparseFieldsets([]), $context);
    }
}
