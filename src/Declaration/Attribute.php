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

namespace Polunich\WpJsonApi\Declaration;

use Closure;
use InvalidArgumentException;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Metadata\AttributeMetadata;
use Polunich\WpJsonApi\Metadata\WriteRule;
use Polunich\WpJsonApi\Text\Printable;

/**
 * a mutable builder, as `ResourceType` is: the flags may be repeated, `filter()` appends,
 * and a second `accessor()` or `description()` throws.
 * `build()` hands everything else to `AttributeMetadata`, which refuses what no attribute can be
 */
final class Attribute
{
    private ?Closure $accessor = null;

    private bool $creatable = false;

    private bool $updatable = false;

    private bool $required = false;

    private bool $sortable = false;

    /**
     * @var list<string>
     */
    private array $operators = [];

    private ?string $description = null;

    private bool $deprecated = false;

    /**
     * @param Codec<mixed> $codec
     */
    private function __construct(private string $name, private Codec $codec) {}

    /**
     * `build()` checks the name against the rules of a field name
     *
     * @param Codec<mixed> $codec decodes, encodes and describes the value
     */
    public static function make(string $name, Codec $codec): self
    {
        return new self($name, $codec);
    }

    /**
     * reads the value from a record; the codec encodes the value.
     * an attribute without an accessor is write-only
     *
     * @template TRecord of object
     *
     * @param Closure(TRecord): mixed $accessor
     *
     * @throws InvalidArgumentException when the attribute already declares an accessor
     */
    public function accessor(Closure $accessor): self
    {
        if ($this->accessor !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'an accessor');
        }

        $this->accessor = $accessor;

        return $this;
    }

    /**
     * a create may send the attribute; without this call one that does gets 422 at the member
     */
    public function creatable(): self
    {
        $this->creatable = true;

        return $this;
    }

    /**
     * an update may send the attribute; without this call one that does gets 422 at the member
     */
    public function updatable(): self
    {
        $this->updatable = true;

        return $this;
    }

    /**
     * a create must send the attribute; one that does not gets 422 at the enclosing object.
     * requires `creatable()` as well, or `build()` throws
     */
    public function required(): self
    {
        $this->required = true;

        return $this;
    }

    /**
     * a sort may order by the attribute.
     * requires an accessor and a codec that admits a scalar type, or `build()` throws
     */
    public function sortable(): self
    {
        $this->sortable = true;

        return $this;
    }

    /**
     * the operators a filter may apply to the attribute, built-in or custom, by name;
     * a request with any other operator gets 400.
     * calls append, and a name given twice fails `build()`.
     * an unknown name, or a text operator on a codec that admits no string, fails the API build
     *
     * @param string ...$operators such as `@eq` or `@containsi`
     */
    public function filter(string ...$operators): self
    {
        foreach ($operators as $operator) {
            $this->operators[] = $operator;
        }

        return $this;
    }

    /**
     * the description of the attribute in the contract
     *
     * @throws InvalidArgumentException when the attribute already declares a description
     */
    public function description(string $description): self
    {
        if ($this->description !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a description');
        }

        $this->description = $description;

        return $this;
    }

    /**
     * marks the attribute deprecated in the contract
     */
    public function deprecated(): self
    {
        $this->deprecated = true;

        return $this;
    }

    /**
     * @throws InvalidArgumentException when `AttributeMetadata` or `WriteRule` refuses it
     *
     * @internal
     */
    public function build(): AttributeMetadata
    {
        return new AttributeMetadata(
            $this->name,
            $this->codec,
            $this->accessor,
            new WriteRule($this->creatable, $this->updatable, $this->required),
            $this->sortable,
            $this->operators,
            $this->description,
            $this->deprecated,
        );
    }

    /**
     * the name is escaped: `build()` has not checked it yet
     */
    private function declaration(): string
    {
        return sprintf('attribute "%s"', Printable::of($this->name));
    }
}
