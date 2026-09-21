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

use InvalidArgumentException;
use LogicException;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;

/**
 * the negotiation and `$includeSupplied` are needed before any operation exists:
 * preconditions are evaluated before the content of a write is parsed,
 * and a response to a request that supplies `include` carries no entity tag.
 * `$record` carries the target or parent loaded for the checks, which a processor reuses.
 *
 * a value, never a register: `withRecord()` and `atPosition()` return copies,
 * so one operation of an atomic request cannot leak its record into the next
 *
 * @internal
 */
final readonly class ExecutionContext
{
    /**
     * @param MediaType            $contentType       the negotiated response media type,
     *                                                with its applied ext and profile
     * @param ?string              $contentLanguage   `null` when the negotiation resolved none
     * @param bool                 $cursorPagination  whether the client asked to page by cursor:
     *                                                the strategy of nested linkage pages
     *                                                and of the read-back of a relationship write
     * @param list<SecurityScheme> $securitySchemes   those of the requested operation,
     *                                                which a 401 challenges
     * @param ?int                 $operationPosition zero-based; `null` outside an atomic request
     *
     * @throws InvalidArgumentException when the position is negative
     */
    public function __construct(
        public ServerRequest $request,
        public MediaType $contentType,
        public ?string $contentLanguage,
        public bool $includeSupplied,
        public bool $cursorPagination,
        public array $securitySchemes,
        public ?object $record = null,
        public ?int $operationPosition = null,
    ) {
        if ($operationPosition !== null && $operationPosition < 0) {
            throw new InvalidArgumentException(sprintf(
                'the position of an operation inside an atomic request is counted from zero, and %d names'
                . ' no operation of it',
                $operationPosition,
            ));
        }
    }

    public function withRecord(object $record): self
    {
        return new self(
            $this->request,
            $this->contentType,
            $this->contentLanguage,
            $this->includeSupplied,
            $this->cursorPagination,
            $this->securitySchemes,
            $record,
            $this->operationPosition,
        );
    }

    /**
     * @throws InvalidArgumentException when the position is negative
     */
    public function atPosition(int $position): self
    {
        return new self(
            $this->request,
            $this->contentType,
            $this->contentLanguage,
            $this->includeSupplied,
            $this->cursorPagination,
            $this->securitySchemes,
            null,
            $position,
        );
    }

    public function isAtomic(): bool
    {
        return $this->operationPosition !== null;
    }

    /**
     * @throws LogicException when no target or parent was loaded
     */
    public function record(): object
    {
        return $this->record ?? throw new LogicException(
            'no record was loaded for this operation, and a kind that acts on an existing resource reads'
            . ' the target or the parent the server loaded before the operation ran',
        );
    }
}
