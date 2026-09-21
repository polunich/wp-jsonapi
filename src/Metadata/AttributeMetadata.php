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

use Closure;
use InvalidArgumentException;
use LogicException;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Text\Printable;

/**
 * @internal
 */
final readonly class AttributeMetadata
{
    /**
     * derived, not declared: true when `Schema::scalarTypes()` of the codec admits null
     */
    public bool $nullable;

    /**
     * @param Codec<mixed> $codec
     * @param ?Closure     $accessor    the project's `Closure(TRecord): T`;
     *                                  null for a write-only attribute
     * @param list<string> $operators   the names of the filter operators it accepts
     * @param ?string      $description shown in the contract
     * @param bool         $deprecated  whether the contract marks it deprecated
     *
     * @throws InvalidArgumentException when the name is no member name, an @-member, type or id;
     *                                  the attribute has no accessor and a read-only write rule;
     *                                  it is sortable without an accessor
     *                                  or with a codec that admits no scalar type;
     *                                  an operator is declared twice;
     *                                  or the description is empty
     */
    public function __construct(
        public string $name,
        public Codec $codec,
        private ?Closure $accessor,
        public WriteRule $writeRule,
        public bool $sortable,
        public array $operators,
        public ?string $description,
        public bool $deprecated,
    ) {
        ResourceFieldName::check($name, 'attribute');
        DeclaredText::check($description, sprintf('attribute "%s"', $name), 'description');

        if ($accessor === null && !$writeRule->creatable && !$writeRule->updatable) {
            throw new InvalidArgumentException(sprintf(
                'attribute "%s" has no accessor and a read-only write rule, so it is never read and never written, and'
                . ' the contract would describe a member no request or response carries',
                $name,
            ));
        }

        $scalarTypes = $codec->schema()->scalarTypes();

        if ($sortable && $accessor === null) {
            throw new InvalidArgumentException(sprintf(
                'attribute "%s" is sortable and has no accessor, and a sort orders records by a value read from them',
                $name,
            ));
        }

        if ($sortable && $scalarTypes === []) {
            throw new InvalidArgumentException(sprintf(
                'attribute "%s" is sortable and its codec admits no scalar type, and a sort orders records by a'
                . ' value a storage compares',
                $name,
            ));
        }

        $seen = [];

        foreach ($operators as $operator) {
            if (array_key_exists($operator, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    'attribute "%s" declares operator "%s" twice',
                    $name,
                    Printable::of($operator),
                ));
            }

            $seen[$operator] = true;
        }

        $this->nullable = in_array(JsonType::Null, $scalarTypes, true);
    }

    public function hasAccessor(): bool
    {
        return $this->accessor !== null;
    }

    /**
     * the value is not checked here: the codec checks it when it encodes it
     *
     * @throws LogicException when the attribute is write-only; ask `hasAccessor()` first
     */
    public function valueOf(object $record): mixed
    {
        if ($this->accessor === null) {
            throw new LogicException(sprintf(
                'attribute "%s" has no accessor, so no client reads it',
                $this->name,
            ));
        }

        return ($this->accessor)($record);
    }
}
