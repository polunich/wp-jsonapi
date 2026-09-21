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
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\FetchResource;
use Polunich\WpJsonApi\Operation\Operation;
use UnexpectedValueException;

/**
 * no store is called for the resource itself:
 * it is the target already loaded for the checks, with the fieldset of the operation,
 * and a second load could return a different result
 *
 * @internal
 */
final readonly class FetchResourceProcessor implements OperationProcessor
{
    public function __construct(
        private ResourceRegistry $resources,
        private IncludeLoader $includes,
        private Preconditions $preconditions,
    ) {}

    /**
     * @throws LogicException           when the operation is of another kind, no target was loaded,
     *                                  or a capability the walk reads is not bound
     * @throws UnexpectedValueException when the id accessor returns no non-empty string,
     *                                  a store breaks its contract,
     *                                  or the version accessor returns no string
     * @throws OutOfBoundsException     when a type or a relationship is not declared
     */
    #[Override]
    public function process(Operation $operation, ExecutionContext $context): ResourceResult
    {
        if (!$operation instanceof FetchResource) {
            throw new LogicException(sprintf(
                'a fetch_resource processor processes a fetch_resource operation, and this one is %s',
                $operation->kind()->value,
            ));
        }

        $type = $operation->target->type;
        $resource = ResourceRecord::of($this->resources->get($type), $context->record());

        return new ResourceResult(
            $type,
            $resource,
            $this->includes->load(GraphRequest::of($operation), [$resource], $context),
            $operation->fields,
            200,
            $this->preconditions->entityTag($type, $context->record(), $context),
        );
    }
}
