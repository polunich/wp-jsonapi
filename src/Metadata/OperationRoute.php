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
use LogicException;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Routing\PathTemplate;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\RouteTarget;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the route of one operation kind of a type: one route carries one kind,
 * so a check of the route knows what it guards without reading the request method
 *
 * @internal
 */
final readonly class OperationRoute extends GeneratedRoute
{
    /**
     * @param ?string                $relationship set exactly when the kind addresses one
     * @param ?PathParameter         $idParameter  the parameter that carries the id of the type,
     *                                             set exactly when the kind addresses an id
     * @param non-empty-list<string> $path         the literal segments of the path of the type
     *
     * @throws InvalidArgumentException when the type or the relationship is empty,
     *                                  or a relationship or an id parameter is given
     *                                  where the kind addresses none, or missing where it does
     */
    public function __construct(
        public OperationKind $operationKind,
        public string $type,
        public ?string $relationship,
        public ?PathParameter $idParameter,
        array $path,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('a route addresses a resource type, and a type is never empty');
        }

        if ($relationship === '') {
            throw new InvalidArgumentException('a route addresses a relationship, whose name is never empty');
        }

        self::checkRelationship($operationKind, $relationship);

        $routeKind = $operationKind->route();
        self::checkIdParameter($routeKind, $idParameter);

        parent::__construct(
            $routeKind,
            new PathTemplate($routeKind->segments($path, $idParameter, $relationship)),
            $operationKind->methods(),
        );
    }

    public function subject(): string
    {
        return sprintf('%s route of type "%s"', $this->operationKind->value, Printable::of($this->type));
    }

    /**
     * the id stays as the client wrote it: the id codec runs later, where the store is addressed
     *
     * @throws LogicException           when the route addresses an id
     *                                  and the request carries none under its parameter name,
     *                                  which a pattern rendered from this route always captures:
     *                                  a caller bug, not a client error
     * @throws InvalidArgumentException when that id is the empty string,
     *                                  which `Routing\RouteTarget` refuses: a caller bug too
     */
    public function target(ServerRequest $request): RouteTarget
    {
        $id = null;

        if ($this->idParameter !== null) {
            $id = $request->path[$this->idParameter->name] ?? throw new LogicException(sprintf(
                'the %s route addresses an id, and the request carries no route parameter "%s"',
                $this->routeKind->value,
                $this->idParameter->name,
            ));
        }

        return new RouteTarget($this->routeKind, $this->type, $id, $this->relationship);
    }

    /**
     * @throws InvalidArgumentException when the kind addresses a relationship and none is given,
     *                                  or the reverse
     */
    private static function checkRelationship(OperationKind $operationKind, ?string $relationship): void
    {
        if ($relationship === null && $operationKind->targetsRelationship()) {
            throw new InvalidArgumentException(sprintf(
                'a %s route addresses a relationship of its type, and it names none',
                $operationKind->value,
            ));
        }

        if ($relationship !== null && !$operationKind->targetsRelationship()) {
            throw new InvalidArgumentException(sprintf(
                'a %s route addresses no relationship, got "%s"',
                $operationKind->value,
                Printable::of($relationship),
            ));
        }
    }

    /**
     * @throws InvalidArgumentException when the route kind addresses an id and none is given,
     *                                  or the reverse
     */
    private static function checkIdParameter(RouteKind $routeKind, ?PathParameter $idParameter): void
    {
        if ($idParameter === null && $routeKind->addressesId()) {
            throw new InvalidArgumentException(sprintf(
                'the %s route addresses an id, and it names no path parameter to carry it',
                $routeKind->value,
            ));
        }

        if ($idParameter !== null && !$routeKind->addressesId()) {
            throw new InvalidArgumentException(sprintf(
                'the %s route addresses no id, got the path parameter "%s"',
                $routeKind->value,
                $idParameter->name,
            ));
        }
    }
}
