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

use InvalidArgumentException;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Metadata\AtomicRouteMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Routing\PathTemplate;
use Polunich\WpJsonApi\Text\Printable;

/**
 * declares one route of the Atomic Operations extension beyond its path:
 * `POST` at the path, running the operations of a request in order and all or nothing,
 * inside the transaction of the API's `Store\TransactionBoundary`.
 *
 * the path is written as the path of an endpoint, with literal segments alone:
 * each operation names its target in the document.
 * `types()` limits the route to some types, and an operation that targets another one
 * gets 409; without it the route takes every type the API declares.
 *
 * the builder is mutable like `Endpoint`, so a call on a line of its own still counts,
 * and each part is written once. it is read when the API is built,
 * so a change made afterwards does not reach that API. a name is required
 */
final class AtomicRoute
{
    private ?string $name = null;

    /** @var ?list<string> */
    private ?array $types = null;

    private ?string $tag = null;

    private ?string $description = null;

    /** @var ?list<SecurityScheme> */
    private ?array $securitySchemes = null;

    /**
     * @internal created by the builder of the API
     */
    public function __construct(private string $path) {}

    /**
     * the operationId of the route in the contract,
     * a letter or `_`, then letters, digits and `_`,
     * unique among the endpoints and the atomic routes of the API
     *
     * @throws InvalidArgumentException when a name is written twice
     */
    public function name(string $name): self
    {
        if ($this->name !== null) {
            throw WrittenTwice::refusal($this->owner(), 'a name');
        }

        $this->name = $name;

        return $this;
    }

    /**
     * the types an operation of this route may target
     *
     * @throws InvalidArgumentException when the types are written twice, or none is named
     */
    public function types(string ...$types): self
    {
        if ($this->types !== null) {
            throw WrittenTwice::refusal($this->owner(), 'its types');
        }

        if ($types === []) {
            throw new InvalidArgumentException(sprintf(
                '%s names no type in types(), so it would accept no operation',
                $this->owner(),
            ));
        }

        $this->types = array_values($types);

        return $this;
    }

    /**
     * the tag of the route in the contract;
     * without one it takes its type where it has one, else `atomic:operations`
     *
     * @throws InvalidArgumentException when a tag is written twice
     */
    public function tag(string $tag): self
    {
        if ($this->tag !== null) {
            throw WrittenTwice::refusal($this->owner(), 'a tag');
        }

        $this->tag = $tag;

        return $this;
    }

    /**
     * the description of the route in the contract
     *
     * @throws InvalidArgumentException when a description is written twice
     */
    public function description(string $description): self
    {
        if ($this->description !== null) {
            throw WrittenTwice::refusal($this->owner(), 'a description');
        }

        $this->description = $description;

        return $this;
    }

    /**
     * the schemes the contract lists for the route,
     * as `Endpoint::securitySchemes()` lists those of an endpoint
     *
     * @throws InvalidArgumentException when the schemes are written twice
     */
    public function securitySchemes(SecurityScheme ...$schemes): self
    {
        if ($this->securitySchemes !== null) {
            throw WrittenTwice::refusal($this->owner(), 'its security schemes');
        }

        $this->securitySchemes = array_values($schemes);

        return $this;
    }

    /**
     * @throws InvalidArgumentException when the route declares no name, its path is refused
     *                                  or holds a parameter, or it names a type that is not
     *                                  declared, or one twice
     *
     * @internal
     */
    public function build(ResourceRegistry $resources): AtomicRouteMetadata
    {
        $name = $this->name ?? throw new InvalidArgumentException(sprintf(
            '%s declares no name, and its name is the operationId of the contract',
            $this->owner(),
        ));
        $owner = $this->owner();

        if (str_contains($this->path, '{') || str_contains($this->path, '}')) {
            throw new InvalidArgumentException(sprintf(
                '%s writes the path "%s" with a parameter, and an atomic route takes none: each operation'
                . ' names its target in the document',
                $owner,
                Printable::of($this->path),
            ));
        }

        $types = [];

        foreach ($this->types ?? [] as $type) {
            $types[] = Endpoint::declaredType($type, $resources, $owner);
        }

        if ($this->types === null) {
            foreach ($resources->all() as $resource) {
                $types[] = $resource->type;
            }
        }

        return new AtomicRouteMetadata(
            $name,
            PathTemplate::written($this->path, [], $owner),
            $types,
            $this->tag,
            $this->description,
            $this->securitySchemes,
        );
    }

    /**
     * the name is escaped: `build()` has not checked it yet
     */
    private function owner(): string
    {
        return $this->name === null
            ? sprintf('the atomic route %s', Printable::of($this->path))
            : sprintf('the atomic route "%s"', Printable::of($this->name));
    }
}
