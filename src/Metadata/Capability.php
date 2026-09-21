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

/**
 * a port a resource binds an implementation to.
 * the implementations of a resource are keyed by the case value, not by the port class:
 * this module cannot depend on the ports,
 * and a class name kept in a string would escape the namespace prefixing of a bundling plugin
 *
 * @internal
 */
enum Capability: string
{
    case CollectionReader = 'collection_reader';

    case ResourceLoader = 'resource_loader';

    case RelatedLoader = 'related_loader';

    case ResourceCreator = 'resource_creator';

    case ResourceUpdater = 'resource_updater';

    case ResourceDeleter = 'resource_deleter';

    case RelationshipReplacer = 'relationship_replacer';

    case RelationshipAdder = 'relationship_adder';

    case RelationshipRemover = 'relationship_remover';
}
