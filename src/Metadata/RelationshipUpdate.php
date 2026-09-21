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

namespace Polunich\WpJsonApi\Metadata;

use Polunich\WpJsonApi\Operation\OperationKind;

/**
 * `Add` and `Remove` apply to to-many relationships only:
 * the spec updates a to-one relationship with PATCH alone
 *
 * @internal
 */
enum RelationshipUpdate: string
{
    case Replace = 'replace';

    case Add = 'add';

    case Remove = 'remove';

    public static function of(OperationKind $kind): ?self
    {
        return match ($kind) {
            OperationKind::ReplaceRelationship => self::Replace,
            OperationKind::AddToRelationship => self::Add,
            OperationKind::RemoveFromRelationship => self::Remove,
            OperationKind::FetchCollection,
            OperationKind::FetchResource,
            OperationKind::FetchRelated,
            OperationKind::FetchRelationship,
            OperationKind::CreateResource,
            OperationKind::UpdateResource,
            OperationKind::DeleteResource => null,
        };
    }
}
