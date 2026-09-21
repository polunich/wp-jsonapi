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

namespace Polunich\WpJsonApi\Execution\Result;

use InvalidArgumentException;
use LogicException;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Text\Printable;
use UnexpectedValueException;

/**
 * one resource of a document: its type, its id and, where the library holds it, its record.
 *
 * `of()` reads the id once through the id accessor of the declaration,
 * so every place that names the resource carries the same string.
 * the record is `null` for an identifier known without it:
 * a to-one id read off the parent, or a related resource the client may not see.
 * such an identifier never becomes an included resource
 *
 * @internal
 */
final readonly class ResourceRecord
{
    /**
     * @throws InvalidArgumentException when the type or the id is empty
     */
    public function __construct(
        public string $type,
        public string $id,
        public ?object $record,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('a resource of a document names its type, and a type is never empty'
                . ' [spec]');
        }

        if ($id === '') {
            throw new InvalidArgumentException(sprintf(
                'a resource of type "%s" carries an empty id, which identifies no resource [spec]',
                Printable::of($type),
            ));
        }
    }

    /**
     * @throws UnexpectedValueException when the id accessor returns no non-empty string
     */
    public static function of(ResourceMetadata $resource, object $record): self
    {
        return new self($resource->type, $resource->id->idOf($record), $record);
    }

    /**
     * refuses an identifier without a record,
     * rather than hand `null` to the accessors of the declaration that read fields off it
     *
     * @throws LogicException when the library holds no record for this resource
     */
    public function requireRecord(): object
    {
        return $this->record ?? throw new LogicException(sprintf(
            'the library holds no record of type "%s" and id "%s": an identifier read off the parent, or'
            . ' a related resource the client may not see, carries none',
            Printable::of($this->type),
            Printable::of($this->id),
        ));
    }
}
