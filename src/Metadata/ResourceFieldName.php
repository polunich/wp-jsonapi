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

use InvalidArgumentException;
use Polunich\WpJsonApi\Document\MemberName;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the spec counts no @-member as a field,
 * and refusing @-members also keeps a field name clear of the filter operators.
 * `type` and `id` share one namespace with the fields
 *
 * @internal
 */
final readonly class ResourceFieldName
{
    private const RESERVED_NAMES = ['type', 'id'];

    /**
     * @param 'attribute'|'relationship' $field the kind of field the message names
     *
     * @throws InvalidArgumentException when the name is no member name, an @-member, type or id
     */
    public static function check(string $name, string $field): void
    {
        if (!MemberName::isValid($name)) {
            throw new InvalidArgumentException(sprintf(
                '%s "%s" is no member name',
                $field,
                Printable::of($name),
            ));
        }

        if (str_starts_with($name, '@')) {
            throw new InvalidArgumentException(sprintf(
                '%s "%s" is an @-member, and an @-member is ignored when interpreting the definitions of the'
                . ' specification, a field among them [spec, @-Members]',
                $field,
                $name,
            ));
        }

        if (in_array($name, self::RESERVED_NAMES, true)) {
            throw new InvalidArgumentException(sprintf(
                '%s "%s" shares the namespace of type and id, and no attribute or relationship is named type or'
                . ' id [spec, Fields]',
                $field,
                $name,
            ));
        }
    }
}
