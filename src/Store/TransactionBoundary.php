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

namespace Polunich\WpJsonApi\Store;

/**
 * the transaction the operations of an atomic request run in.
 * the library never touches storage itself,
 * so an API serves atomic operations only when the project provides this boundary.
 *
 * the operations run in request order; the first one that fails is followed by `rollBack()`,
 * and no later operation runs
 */
interface TransactionBoundary
{
    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;
}
