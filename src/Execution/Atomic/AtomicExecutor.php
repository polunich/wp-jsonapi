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

namespace Polunich\WpJsonApi\Execution\Atomic;

use LogicException;
use Polunich\WpJsonApi\Access\Gate;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\InternalServerErrorException;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\Executor;
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\AtomicResult;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Operation\AtomicOperation;
use Polunich\WpJsonApi\Operation\AtomicOperations;
use Polunich\WpJsonApi\Store\TransactionBoundary;
use Polunich\WpJsonApi\Text\Printable;
use Throwable;

/**
 * runs the operations of one atomic request in order inside one transaction:
 * the first failure rolls back and stops the run, as the atomic operations extension requires.
 *
 * each operation passes the checks of a single request.
 * the kind-level permission of every operation is asked before `begin()`,
 * so a refusal calls no store and rolls nothing back.
 *
 * a failure is wrapped in an `OperationFailedException` with its position.
 * what `begin()`, `commit()` or `rollBack()` throws belongs to no operation and leaves unwrapped,
 * so a `rollBack()` that throws replaces the failure it was handling.
 *
 * a write the store only accepted gets 500:
 * the extension has no 202 and requires every operation to be performed
 *
 * @internal
 */
final readonly class AtomicExecutor
{
    public function __construct(
        private ApiMetadata $api,
        private ImplementationResolver $implementations,
        private Gate $gate,
        private Preconditions $preconditions,
        private Executor $executor,
    ) {}

    /**
     * @throws OperationFailedException the failure of one operation, after any rollback
     * @throws LogicException           when the API declares no transaction boundary,
     *                                  which it declares exactly where the extension is enabled
     * @throws Throwable                what `begin()`, `commit()` or `rollBack()` threw, unwrapped
     */
    public function execute(AtomicOperations $operations, ExecutionContext $context): AtomicResult
    {
        $boundary = $this->api->transactionBoundary ?? throw new LogicException(
            'this API declares no transaction boundary and therefore no atomic route, atomic operations'
            . ' being enabled exactly where a project provides the transaction the operations run in',
        );
        $transactions = $this->implementations->resolve($boundary, TransactionBoundary::class);
        $localIds = new LocalIds();
        $results = [];

        $this->checkEveryKind($operations, $context);

        $transactions->begin();

        foreach ($operations->all() as $position => $atomic) {
            try {
                $results[] = $this->perform($atomic, $position, $context, $localIds);
            } catch (Throwable $failure) {
                $transactions->rollBack();

                throw new OperationFailedException($position, $failure);
            }
        }

        $transactions->commit();

        return new AtomicResult($results);
    }

    /**
     * the attempt is built from what `AtomicOperation` declares before its operation exists;
     * `build()` refuses to build an operation that differs from it
     *
     * @throws OperationFailedException the refusal of the gate, at the position of its operation
     */
    private function checkEveryKind(AtomicOperations $operations, ExecutionContext $context): void
    {
        foreach ($operations->all() as $position => $atomic) {
            try {
                $this->gate->check(OperationAttempt::forKind(
                    $atomic->kind,
                    $atomic->type,
                    $atomic->relationship,
                    $context->request,
                ), $context->securitySchemes);
            } catch (Throwable $failure) {
                throw new OperationFailedException($position, $failure);
            }
        }
    }

    /**
     * @throws Throwable what the operation failed with, which `execute()` wraps after the rollback
     */
    private function perform(
        AtomicOperation $atomic,
        int $position,
        ExecutionContext $context,
        LocalIds $localIds,
    ): ResourceResult|NoContentResult {
        $operation = $atomic->build($localIds->idLookup());
        $scoped = $context->atPosition($position);

        if ($operation->kind()->actsOnExistingResource()) {
            $scoped = $scoped->withRecord($this->executor->loadTarget(
                $this->executor->targetRequest($operation),
                $operation->kind(),
                $operation->relationship(),
                $scoped,
            ));
        }

        $this->preconditions->refuseUnconditionalOperation($operation->kind(), $operation->type());

        $result = $this->executor->execute($operation, $scoped);

        if ($result instanceof AcceptedResult) {
            throw new InternalServerErrorException([new ErrorDescription(ErrorCode::InternalError)]);
        }

        if (!$result instanceof ResourceResult && !$result instanceof NoContentResult) {
            throw new LogicException(sprintf(
                'the %s operation at position %d returned a %s, and an operation of an atomic request returns'
                . ' the resource it wrote or no data at all [ext-atomic]',
                $atomic->kind->value,
                $position,
                $result::class,
            ));
        }

        if ($atomic->lid !== null) {
            if (!$result instanceof ResourceResult || $result->resource === null) {
                throw new LogicException(sprintf(
                    'the operation at position %d gives the resource of type "%s" the lid "%s" and returned no'
                    . ' resource, while a lid is given by a create, whose result carries the record it made',
                    $position,
                    Printable::of($atomic->type),
                    Printable::of($atomic->lid),
                ));
            }

            $localIds->assign($atomic->type, $atomic->lid, $result->resource->id);
        }

        return $result;
    }
}
