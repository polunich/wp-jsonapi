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
use OutOfBoundsException;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\Filter\Operators;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Text\Printable;

/**
 * a declared API, checked as a whole when it is built.
 *
 * the name and the version are `info.title` and `info.version` of the contract,
 * which OpenAPI requires, so neither may be empty.
 * `$routes` is built here, so routes that collide are refused here too,
 * as are the rules that span the types and the endpoints:
 *
 * - the names of the endpoints and the atomic routes, their operationIds, are unique;
 * - a transaction boundary is declared exactly where an atomic route is;
 * - a custom query parameter holds a character outside a-z, unless the API waives the rule;
 * - no query parameter of an endpoint or a route takes the name of a language parameter;
 * - a security scheme name stands for one scheme across the API and its operations,
 *   since `components.securitySchemes` holds it once;
 * - a monitor type, of a type or of an endpoint, responds to the fetch of its resources,
 *   on its own route or through an endpoint in its place;
 * - a type binds a resource loader exactly when something reads through it;
 * - a default sort of a type is read by its collection route
 *   or by the page of an endpoint that declares none of its own,
 *   and the default sort of an endpoint orders the type of its page
 *
 * @internal
 */
final readonly class ApiMetadata
{
    /** @var non-falsy-string such as `acme/v1` */
    public string $restNamespace;

    public RouteTable $routes;

    /**
     * every scheme the API and its operations declare, in the order first declared:
     * the entries of `components.securitySchemes`
     *
     * @var list<SecurityScheme>
     */
    public array $componentSecuritySchemes;

    /**
     * the operator table keyed by `operatorName()`
     *
     * @var array<string, OperatorMetadata>
     */
    private array $written;

    /**
     * @param list<EndpointMetadata>          $endpoints           in declaration order
     * @param list<AtomicRouteMetadata>       $atomicRoutes        in declaration order
     * @param array<string, OperatorMetadata> $operators           keyed by name,
     *                                                             the built-in operators first
     * @param list<SecurityScheme>            $securitySchemes
     * @param ?ImplementationReference        $transactionBoundary wraps every atomic route
     * @param bool                            $strictQueryParameterNames
     *                                                             whether a custom query parameter
     *                                                             needs a character outside a-z,
     *                                                             as JSON:API asks
     *
     * @throws InvalidArgumentException when the name, the version or the namespace is empty;
     *                                  the namespace starts or ends with a slash;
     *                                  a list names a security scheme twice,
     *                                  or two declarations give one name to different schemes;
     *                                  the operators do not start with the built-in ones in order,
     *                                  repeat a name, key one by another name,
     *                                  miss an operator an attribute or an id declares,
     *                                  or name a custom one as a client writes a built-in one;
     *                                  a text operator is declared on an attribute or an id
     *                                  that admits no string;
     *                                  two routes of the API collide;
     *                                  two endpoints or atomic routes share a name;
     *                                  a transaction boundary is declared without an atomic route,
     *                                  or an atomic route without a boundary;
     *                                  a custom query parameter is a-z alone under the strict rule;
     *                                  a query parameter of an endpoint or a route shadows
     *                                  a language parameter;
     *                                  a monitor type does not respond
     *                                  to the fetch of its resources;
     *                                  a resource loader is read and not bound,
     *                                  or bound and never read;
     *                                  or a default sort is never read,
     *                                  or names a path the type of its page cannot sort by
     */
    public function __construct(
        public string $name,
        public string $version,
        string $restNamespace,
        public ResourceRegistry $resources,
        public array $endpoints,
        public array $atomicRoutes,
        public array $operators,
        public array $securitySchemes,
        public ?ImplementationReference $transactionBoundary,
        public LanguageSources $languageSources,
        public NamingPolicy $naming,
        public bool $strictQueryParameterNames,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('the name of an API is info.title of its contract, which is never'
                . ' empty');
        }

        if ($version === '') {
            throw new InvalidArgumentException('the version of an API is info.version of its contract, which is'
                . ' never empty');
        }

        // `register_rest_route()` refuses an `empty()` namespace, so `'0'` is empty too
        if ($restNamespace === '' || $restNamespace === '0') {
            throw new InvalidArgumentException(sprintf(
                'the route namespace "%s" is empty to WordPress, which registers no route without a namespace',
                $restNamespace,
            ));
        }

        if (trim($restNamespace, '/') !== $restNamespace) {
            throw new InvalidArgumentException(sprintf(
                'the route namespace "%s" starts or ends with a slash, which WordPress reports and trims',
                Printable::of($restNamespace),
            ));
        }

        $this->restNamespace = $restNamespace;

        $this->componentSecuritySchemes = $this->componentSchemes();
        $this->checkOperatorTable();
        $this->checkFieldOperators();
        $this->written = $this->writtenOperators();

        $this->checkOperationNames();
        $this->checkTransactionBoundary();
        $this->checkQueryParameterNames();
        $this->checkLanguageShadows();

        $this->routes = RouteTable::of($resources, $endpoints, $atomicRoutes);

        $this->checkMonitors();
        $this->checkResourceLoaders();
        $this->checkDefaultSorts();
    }

    /**
     * the schemes the operation of the route accepts: those it declares, else those of the API.
     * null, a request on no route, takes those of the API
     *
     * @return list<SecurityScheme>
     *
     * @throws OutOfBoundsException when the route names an undeclared type or relationship
     */
    public function securitySchemesOf(?Route $route): array
    {
        $declared = match (true) {
            $route instanceof EndpointRoute => $route->endpoint->securitySchemes,
            $route instanceof AtomicRoute => $route->metadata->securitySchemes,
            $route instanceof OperationRoute => $route->relationship === null
                ? $this->resources->get($route->type)->securitySchemesOf($route->operationKind)
                : $this->resources->relationshipOf($route->type, $route->relationship)
                    ->securitySchemesOf($route->operationKind),
            default => null,
        };

        return $declared ?? $this->securitySchemes;
    }

    /**
     * the operator a client names in a filter, null for a name the table does not hold
     */
    public function operatorWritten(string $name): ?OperatorMetadata
    {
        return $this->written[$name] ?? null;
    }

    /**
     * the name a client writes: a built-in operator as the naming policy spells it,
     * a custom one as declared
     */
    public function operatorName(OperatorMetadata $operator): string
    {
        return $operator->isCustom() ? $operator->name : $this->naming->name($operator->name);
    }

    /**
     * @throws InvalidArgumentException when two endpoints or atomic routes share a name
     */
    private function checkOperationNames(): void
    {
        $seen = [];
        $named = [];

        foreach ($this->endpoints as $endpoint) {
            $named[] = [$endpoint->name, sprintf('the endpoint "%s"', $endpoint->name)];
        }

        foreach ($this->atomicRoutes as $route) {
            $named[] = [$route->name, sprintf('the atomic route "%s"', $route->name)];
        }

        foreach ($named as [$name, $subject]) {
            if (array_key_exists($name, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    '%s and %s share their name, and the name is the operationId, which "MUST be unique among all'
                    . ' operations described in the API" (OpenAPI)',
                    $seen[$name],
                    $subject,
                ));
            }

            $seen[$name] = $subject;
        }
    }

    /**
     * a boundary no route runs in is refused as a declaration that can never take effect
     *
     * @throws InvalidArgumentException when a boundary is declared without an atomic route,
     *                                  or an atomic route without a boundary
     */
    private function checkTransactionBoundary(): void
    {
        if ($this->transactionBoundary !== null && $this->atomicRoutes === []) {
            throw new InvalidArgumentException(
                'the API declares a transaction boundary and no atomic route, so no operation runs in it:'
                . ' declare the route with ApiBuilder::atomic()',
            );
        }

        if ($this->transactionBoundary === null && $this->atomicRoutes !== []) {
            throw new InvalidArgumentException(sprintf(
                'the atomic route "%s" runs its operations in one transaction, and the API declares no'
                . ' transaction boundary to provide it: declare one with ApiBuilder::transactionBoundary()',
                $this->atomicRoutes[0]->name,
            ));
        }
    }

    /**
     * @throws InvalidArgumentException when a custom query parameter holds a-z alone
     *                                  under the strict rule
     */
    private function checkQueryParameterNames(): void
    {
        if (!$this->strictQueryParameterNames) {
            return;
        }

        foreach ($this->languageSources->queryParameters() as $name) {
            if (CustomQueryParameterName::isReservedByJsonApi($name)) {
                throw new InvalidArgumentException(self::reservedByJsonApi(sprintf(
                    'the language parameter "%s"',
                    $name,
                )));
            }
        }

        foreach ($this->declaredQueryParameters() as $declared) {
            if (CustomQueryParameterName::isReservedByJsonApi($declared->parameter->name)) {
                throw new InvalidArgumentException(self::reservedByJsonApi(sprintf(
                    'the query parameter "%s" of %s',
                    $declared->parameter->name,
                    $declared->place(),
                )));
            }
        }
    }

    /**
     * @return list<DeclaredQueryParameter>
     */
    private function declaredQueryParameters(): array
    {
        $declared = [];

        foreach ($this->resources->all() as $resource) {
            $type = sprintf('type "%s"', $resource->type);

            foreach ($resource->queryParameters as $key => $parameters) {
                foreach ($parameters as $parameter) {
                    $declared[] = new DeclaredQueryParameter($parameter, $type, OperationKind::from($key));
                }
            }

            foreach ($resource->relationships as $relationship) {
                $owner = sprintf('relationship "%s" of %s', $relationship->name, $type);

                foreach ($relationship->queryParameters as $key => $parameters) {
                    foreach ($parameters as $parameter) {
                        $declared[] = new DeclaredQueryParameter($parameter, $owner, OperationKind::from($key));
                    }
                }
            }
        }

        foreach ($this->endpoints as $endpoint) {
            foreach ($endpoint->queryParameters as $parameter) {
                $declared[] = new DeclaredQueryParameter(
                    $parameter,
                    sprintf('the endpoint "%s"', $endpoint->name),
                    null,
                );
            }
        }

        return $declared;
    }

    private static function reservedByJsonApi(string $parameter): string
    {
        return sprintf(
            '%s holds a-z alone, and JSON:API keeps such names for the parameters it may define: a custom one "contains'
            . ' at least one non a-z character". ApiBuilder::strictQueryParameterNames(false) admits it',
            $parameter,
        );
    }

    /**
     * a language parameter is read on every route,
     * so a declared parameter of the same name would give one value two meanings
     *
     * @throws InvalidArgumentException when a query parameter shadows a language parameter
     */
    private function checkLanguageShadows(): void
    {
        $languages = $this->languageSources->queryParameters();

        foreach ($this->declaredQueryParameters() as $declared) {
            if (in_array($declared->parameter->name, $languages, true)) {
                throw new InvalidArgumentException(sprintf(
                    '%s declares the query parameter "%s"%s, which names the language of a request on every route of'
                    . ' the API',
                    $declared->declaration,
                    $declared->parameter->name,
                    $declared->kind === null ? '' : ' for its ' . $declared->kind->value . ' route',
                ));
            }
        }
    }

    /**
     * "To check the status of the job process,
     * a client can send a request to the location given earlier"
     * (JSON:API recommendations, Asynchronous Processing)
     *
     * @throws InvalidArgumentException when a monitor type does not respond to the fetch
     */
    private function checkMonitors(): void
    {
        foreach ($this->resources->all() as $resource) {
            foreach ($resource->monitorTypes as $kind => $monitorType) {
                $this->checkMonitor($monitorType, sprintf('type "%s" responds to %s', $resource->type, $kind));
            }
        }

        foreach ($this->endpoints as $endpoint) {
            $response = $endpoint->response(202);

            if ($response?->type !== null) {
                $this->checkMonitor($response->type, sprintf('the endpoint "%s" responds', $endpoint->name));
            }
        }
    }

    /**
     * @throws InvalidArgumentException when the type does not respond to the fetch of its resources
     */
    private function checkMonitor(string $monitorType, string $responds): void
    {
        if ($this->routes->fetches(RouteKind::Resource, $monitorType, null)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            '%s with 202 and the monitor type "%s", and a client checks the status of the write through the %s route'
            . ' of that type, so that type cannot exclude it without an endpoint in its place',
            $responds,
            $monitorType,
            OperationKind::FetchResource->value,
        ));
    }

    /**
     * a loader nothing reads is refused as a declaration that can never take effect
     *
     * @throws InvalidArgumentException when a loader is read and not bound, or bound and never read
     */
    private function checkResourceLoaders(): void
    {
        $readers = [];

        foreach ($this->resources->all() as $resource) {
            foreach (OperationKind::cases() as $kind) {
                if ($kind->actsOnExistingResource() && $resource->enables($kind)) {
                    $readers[$resource->type] ??= sprintf('its %s route loads its target through it', $kind->value);
                }
            }
        }

        foreach ($this->resources->all() as $resource) {
            foreach ($resource->relationships as $relationship) {
                if ($relationship->cardinality === Cardinality::ToOne && $relationship->readsRelatedId()) {
                    $readers[$relationship->relatedType] ??= sprintf(
                        'the to-one relationship "%s" of type "%s" loads the resources it points at through it by id',
                        $relationship->name,
                        $resource->type,
                    );
                }
            }
        }

        foreach ($this->endpoints as $endpoint) {
            foreach ($endpoint->bindings as $parameter => $type) {
                $readers[$type] ??= sprintf(
                    'the endpoint "%s" loads the record of its parameter "%s" through it',
                    $endpoint->name,
                    $parameter,
                );
            }
        }

        foreach ($this->resources->all() as $resource) {
            $reader = $readers[$resource->type] ?? null;
            $bound = array_key_exists(Capability::ResourceLoader->value, $resource->capabilities);

            if ($reader !== null && !$bound) {
                throw new InvalidArgumentException(sprintf(
                    'type "%s" binds no resource loader, and %s',
                    $resource->type,
                    $reader,
                ));
            }

            if ($reader === null && $bound) {
                throw new InvalidArgumentException(sprintf(
                    'type "%s" binds a resource loader, and nothing reads through it: the type enables no route on an'
                    . ' existing resource, no to-one relationship with a related id accessor points at it, and no'
                    . ' endpoint binds a parameter to it',
                    $resource->type,
                ));
            }
        }
    }

    /**
     * a default sort nothing reads is refused as a declaration that can never take effect.
     * `ResourceRegistry` checks the paths of the default sort of a type
     *
     * @throws InvalidArgumentException when a default sort of a type is never read,
     *                                  or one of an endpoint names a path its page cannot sort by
     */
    private function checkDefaultSorts(): void
    {
        $read = [];

        foreach ($this->endpoints as $endpoint) {
            $type = $endpoint->pageType();

            if ($type === null) {
                continue;
            }

            if ($endpoint->defaultSort === null) {
                $read[$type] = true;

                continue;
            }

            $this->resources->checkDefaultSort(
                $endpoint->defaultSort,
                $type,
                sprintf('the default sort of the endpoint "%s"', $endpoint->name),
            );
        }

        foreach ($this->resources->all() as $resource) {
            if (
                $resource->defaultSort !== null
                && !$resource->enables(OperationKind::FetchCollection)
                && !array_key_exists($resource->type, $read)
            ) {
                throw new InvalidArgumentException(sprintf(
                    'type "%s" declares a default sort that nothing reads: its collection route is not enabled,'
                    . ' since the type binds no collection reader or excludes the route, and no endpoint responds'
                    . ' with a page of the type without a default sort of its own',
                    $resource->type,
                ));
            }
        }
    }

    /**
     * @return list<SecurityScheme>
     *
     * @throws InvalidArgumentException when a list names a scheme twice,
     *                                  or two declarations give one name to different schemes
     */
    private function componentSchemes(): array
    {
        SecuritySchemes::checkDistinct($this->securitySchemes, sprintf('the API "%s"', Printable::of($this->name)));

        $declared = [];
        $components = [];

        foreach ($this->declaredSchemeLists() as [$declaration, $schemes]) {
            foreach ($schemes as $scheme) {
                $first = $declared[$scheme->name] ?? null;

                if ($first === null) {
                    $declared[$scheme->name] = [$scheme, $declaration];
                    $components[] = $scheme;
                } elseif ($first[0] != $scheme) {
                    throw new InvalidArgumentException(sprintf(
                        '%s declares the security scheme "%s" unlike %s, and a name is one entry of'
                        . ' components.securitySchemes',
                        $declaration,
                        $scheme->name,
                        $first[1],
                    ));
                }
            }
        }

        return $components;
    }

    /**
     * the API's list first, then the lists of the operations in the order of the routes
     *
     * @return list<array{string, list<SecurityScheme>}> the declaration and its list
     */
    private function declaredSchemeLists(): array
    {
        $lists = [[sprintf('the API "%s"', Printable::of($this->name)), $this->securitySchemes]];

        foreach ($this->resources->all() as $resource) {
            foreach ($resource->securitySchemes as $key => $schemes) {
                $lists[] = [sprintf('type "%s" for %s', $resource->type, $key), $schemes];
            }

            foreach ($resource->relationships as $relationship) {
                foreach ($relationship->securitySchemes as $key => $schemes) {
                    $lists[] = [
                        sprintf('relationship "%s" of type "%s" for %s', $relationship->name, $resource->type, $key),
                        $schemes,
                    ];
                }
            }
        }

        foreach ($this->endpoints as $endpoint) {
            if ($endpoint->securitySchemes !== null) {
                $lists[] = [sprintf('the endpoint "%s"', $endpoint->name), $endpoint->securitySchemes];
            }
        }

        foreach ($this->atomicRoutes as $route) {
            if ($route->securitySchemes !== null) {
                $lists[] = [sprintf('the atomic route "%s"', $route->name), $route->securitySchemes];
            }
        }

        return $lists;
    }

    /**
     * the table starts with the built-in operators in the order of `Operators::names()`.
     * matching those positions is enough to keep every custom operator behind them:
     * a custom name never equals a built-in one, and a built-in operator further on repeats a name
     *
     * @throws InvalidArgumentException when a built-in operator is missing or out of place,
     *                                  a name repeats, or an operator is keyed by another name
     */
    private function checkOperatorTable(): void
    {
        $builtIn = Operators::names();
        $seen = [];
        $position = 0;

        foreach ($this->operators as $key => $operator) {
            if (array_key_exists($operator->name, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    'operator "%s" is declared twice',
                    $operator->name,
                ));
            }

            $seen[$operator->name] = true;

            if ($key !== $operator->name) {
                throw new InvalidArgumentException(sprintf(
                    'operator "%s" is held under the key "%s", and the table holds an operator under its name',
                    $operator->name,
                    Printable::of($key),
                ));
            }

            $expected = $builtIn[$position] ?? null;

            if ($expected !== null && $operator->name !== $expected) {
                throw new InvalidArgumentException(sprintf(
                    'the table of operators holds "%s" at position %d, where the built-in operator "%s" stands:'
                    . ' the %d built-in operators come first, in the order of Operators::names()',
                    $operator->name,
                    $position,
                    $expected,
                    count($builtIn),
                ));
            }

            ++$position;
        }

        if ($position < count($builtIn)) {
            throw new InvalidArgumentException(sprintf(
                'the table of operators holds %d operators, and it starts with the %d built-in operators of'
                . ' Operators::names()',
                $position,
                count($builtIn),
            ));
        }
    }

    /**
     * @throws InvalidArgumentException when an operator is missing from the table,
     *                                  or a text operator is declared on an attribute or an id
     *                                  whose codec admits no string
     */
    private function checkFieldOperators(): void
    {
        foreach ($this->resources->all() as $resource) {
            $this->checkOperators(
                sprintf('the id of type "%s"', $resource->type),
                $resource->id->codec,
                $resource->id->operators,
            );

            foreach ($resource->attributes as $attribute) {
                $this->checkOperators(
                    sprintf('attribute "%s" of type "%s"', $attribute->name, $resource->type),
                    $attribute->codec,
                    $attribute->operators,
                );
            }
        }
    }

    /**
     * @param string       $field     names the field in the message
     * @param Codec<mixed> $codec
     * @param list<string> $operators
     *
     * @throws InvalidArgumentException when an operator is missing from the table,
     *                                  or a text operator is declared on a codec
     *                                  that admits no string
     */
    private function checkOperators(string $field, Codec $codec, array $operators): void
    {
        foreach ($operators as $name) {
            $operator = $this->operators[$name] ?? null;

            if ($operator === null) {
                throw new InvalidArgumentException(sprintf(
                    '%s declares operator "%s", which the table of operators does not hold',
                    $field,
                    Printable::of($name),
                ));
            }

            if (
                !$operator->isCustom()
                && Operators::operandShape($name) === Operators::OPERAND_TEXT
                && !in_array(JsonType::String, $codec->schema()->scalarTypes(), true)
            ) {
                throw new InvalidArgumentException(sprintf(
                    '%s declares the text operator "%s", and its codec admits no string',
                    $field,
                    $name,
                ));
            }
        }
    }

    /**
     * `OperatorMetadata::custom()` refuses a built-in name,
     * yet the snake case policy writes the built-in `@notIn` as `@not_in`,
     * a name a custom operator may take
     *
     * @return array<string, OperatorMetadata>
     *
     * @throws InvalidArgumentException when a custom operator is named
     *                                  as a client writes a built-in one
     */
    private function writtenOperators(): array
    {
        $written = [];

        foreach ($this->operators as $operator) {
            $name = $this->operatorName($operator);

            if (array_key_exists($name, $written)) {
                throw new InvalidArgumentException(sprintf(
                    'custom operator "%s" is named as a client writes the built-in operator "%s"',
                    $operator->name,
                    $written[$name]->name,
                ));
            }

            $written[$name] = $operator;
        }

        return $written;
    }
}
