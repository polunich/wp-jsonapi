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
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\Result\Result;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Store\ConstraintViolationException;
use Polunich\WpJsonApi\Store\RelatedResourceNotFoundException;
use Polunich\WpJsonApi\Store\ResourceExistsException;
use Polunich\WpJsonApi\Store\ResourceNotFoundException;
use Polunich\WpJsonApi\Store\VersionMismatchException;
use UnexpectedValueException;

/**
 * what an operation kind does.
 *
 * one processor is bound per kind value, and one processor may serve several kinds.
 * PHP forbids narrowing the parameter, so each implementation first refuses,
 * with a `LogicException`, any operation class it does not serve:
 * a mismatch means a processor bound under the wrong key.
 *
 * a processor checks no permission: the gate has refused any kind the declaration does not enable,
 * so a capability a processor misses is a `LogicException`.
 * the five store failures pass through untranslated
 *
 * @internal
 */
interface OperationProcessor
{
    /**
     * @throws LogicException                   when the operation is of another kind,
     *                                          the context lacks the record the kind needs,
     *                                          or a capability is not bound
     * @throws UnexpectedValueException         when a store breaks a contract
     * @throws OutOfBoundsException             when a type or a relationship is not declared
     * @throws JsonApiException                 an error the processor itself raises
     * @throws ResourceNotFoundException        as the store reported it
     * @throws RelatedResourceNotFoundException as the store reported it
     * @throws ResourceExistsException          as the store reported it
     * @throws ConstraintViolationException     as the store reported it
     * @throws VersionMismatchException         as the store reported it
     */
    public function process(Operation $operation, ExecutionContext $context): Result;
}
