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

namespace Polunich\WpJsonApi\Operation;

/**
 * a write on an existing resource, which may carry the precondition of its If-Match field.
 *
 * the operation is built from the request content, and the precondition, which comes from a header,
 * is attached afterwards with `withPrecondition()`.
 * an operation of an atomic request carries none: an operation object has no member to state one
 */
interface ConditionalWrite extends Operation
{
    /**
     * null for an unconditional write
     */
    public function precondition(): ?Precondition;

    /**
     * a copy that differs in the precondition only
     */
    public function withPrecondition(Precondition $precondition): static;
}
