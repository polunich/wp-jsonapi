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

namespace Polunich\WpJsonApi\OpenApi;

use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use Polunich\WpJsonApi\Http\BasicLanguageRange;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AttributeMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\RouteQuery;
use Polunich\WpJsonApi\Metadata\SortPath;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Filter\Operators;
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Pagination\PageParameter;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Polunich\WpJsonApi\Query\QueryText;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Sort\SortField;
use Polunich\WpJsonApi\Text\Printable;
use stdClass;

/**
 * an operation lists exactly the parameters `Metadata\RouteQuery` returns for its route:
 * the server reads the same result and refuses any other parameter with 400.
 *
 * the filter parameter references a component schema:
 * logical operators and relationship conditions nest filter objects,
 * a recursion no finite inline schema can write.
 *
 * each language source is one entry of `components.parameters`, which every operation references
 *
 * @internal
 */
final readonly class ParameterBuilder
{
    private const PARAMETERS_REFERENCE = '#/components/parameters/';

    /**
     * `deepObject` leaves the "representation of array or object properties" undefined
     * (OpenAPI 3.1.2, Style Values), so only the description can state how a filter nests
     */
    private const NESTING = 'the members of a filter object are the fields of the type, the names of its'
        . ' relationships and the logical operators @and, @or and @not, of which @and and @or carry an array of'
        . ' filter objects; a field carries an object of the operators it declares and @not, as in'
        . ' filter[@or][0][city][@eq]=Kyiv';

    public function __construct(private ApiMetadata $api) {}

    /**
     * the query parameters the route declares, then the families of the specification it processes
     *
     * @param string  $type         the type of the route; on a related or a relationship route,
     *                              the type that declares the relationship
     * @param ?string $relationship given exactly for a related or a relationship route
     *
     * @throws InvalidArgumentException when the relationship is missing for a relationship route,
     *                                  or given for another
     * @throws OutOfBoundsException     when the type or the relationship is not declared
     *
     * @return list<array<string, mixed>>
     */
    public function parameters(OperationKind $kind, string $type, ?string $relationship = null): array
    {
        $resource = $this->api->resources->get($type);
        $declared = null;

        if ($kind->targetsRelationship()) {
            if ($relationship === null) {
                throw new InvalidArgumentException(sprintf(
                    'a %s operation reads the parameters of a relationship route, which names its relationship',
                    $kind->value,
                ));
            }

            $declared = $this->api->resources->relationshipOf($resource->type, $relationship);
        } elseif ($relationship !== null) {
            throw new InvalidArgumentException(sprintf(
                'a %s operation reads the parameters of a resource route, which names no relationship',
                $kind->value,
            ));
        }

        $place = $declared === null
            ? sprintf('the %s route of type "%s"', $kind->value, $resource->type)
            : sprintf('the %s route of relationship "%s" of type "%s"', $kind->value, $declared->name, $resource->type);
        $route = RouteQuery::ofKind($kind, $resource->type, $declared?->name, $this->api->resources);
        $parameters = [];

        foreach ($route->declared as $metadata) {
            $parameters[] = self::declaredQueryParameter($metadata, $place);
        }

        foreach ($route->families as $family) {
            $parameters = [...$parameters, ...$this->familyParameters($family, $kind, $resource, $declared)];
        }

        return $parameters;
    }

    /**
     * a page reads `filter`, `sort` and `page` as the collection route of its type does,
     * with the default sort and the page sizes of the endpoint
     *
     * @return list<array<string, mixed>>
     *
     * @throws OutOfBoundsException when the type of the page is not declared
     */
    public function endpointParameters(EndpointMetadata $endpoint): array
    {
        $route = RouteQuery::ofEndpoint($endpoint);
        $parameters = [];

        foreach ($route->declared as $metadata) {
            $parameters[] = self::declaredQueryParameter($metadata, sprintf('the endpoint "%s"', $endpoint->name));
        }

        $type = $endpoint->pageType();

        if ($type !== null) {
            $resource = $this->api->resources->get($type);
            $parameters = [
                ...$parameters,
                ...$this->filterParameters($resource),
                ...$this->sortParameters($resource, $this->api->resources->endpointDefaultSort($endpoint), 'endpoint'),
                ...$this->pageParameters($this->api->resources->endpointPaginationPolicy($endpoint)),
            ];
        }

        $root = $endpoint->includeRoot();
        $types = [];

        if ($root !== null) {
            if (in_array(ParameterFamily::Include, $route->families, true)) {
                $parameters = [...$parameters, ...$this->includeParameters($this->includePaths($root[0], $root[1]))];
            }

            $types = $root[1] === null
                ? $this->responseTypes($root[0])
                : $this->api->resources->includedTypes($root[0], $root[1]);
        }

        foreach ($endpoint->responses as $response) {
            if (
                $response->kind === ResponseShapeKind::Accepted
                && $response->type !== null
                && !in_array($response->type, $types, true)
            ) {
                $types[] = $response->type;
            }
        }

        if (in_array(ParameterFamily::Fields, $route->families, true)) {
            $parameters = [...$parameters, ...$this->fieldsParameters($types)];
        }

        return $parameters;
    }

    /**
     * `fields[TYPE]` for every type, since a result may be of any type;
     * no `include`, since an atomic document carries no `included`
     *
     * @return list<array<string, mixed>>
     */
    public function atomicParameters(): array
    {
        $types = [];

        foreach ($this->api->resources->all() as $resource) {
            $types[] = $resource->type;
        }

        return $this->fieldsParameters($types);
    }

    /**
     * a query parameter is a string of the grammar the library checks it by;
     * `Accept-Language` has no pattern, since the library disregards a malformed element of it.
     * OpenAPI ignores a header parameter only for `Accept`, `Content-Type` and `Authorization`
     * (OpenAPI 3.1.2, Parameter Object)
     *
     * @return array<string, stdClass>
     */
    public function languageParameters(): array
    {
        $sources = $this->api->languageSources->sources;
        $parameters = [];

        foreach ($sources as $position => $source) {
            $order = '';
            $before = array_slice($sources, 0, $position);
            $after = array_slice($sources, $position + 1);

            if ($before !== []) {
                $order .= sprintf(
                    '. it is read only where the request does not carry %s',
                    self::sourceNames($before, 'or'),
                );
            }

            if ($after !== []) {
                $order .= sprintf('. it takes precedence over %s', self::sourceNames($after, 'and'));
            }

            $name = $source->queryParameter;

            $parameters[self::languageParameterName($source)] = (object) ($name === null
                ? [
                    'name' => FieldName::ACCEPT_LANGUAGE,
                    'in' => 'header',
                    'description' => 'the languages the client prefers, by weight [primary: RFC 9110 12.5.4]; a'
                        . ' malformed element is disregarded' . $order,
                    'schema' => (object) ['type' => 'string'],
                ]
                : [
                    'name' => $name,
                    'in' => 'query',
                    'description' => 'the language the client asks for, one basic language range such as uk or en-GB'
                        . ' [primary: RFC 4647 2.1]; an empty value or one of another form gets 400. every link of the'
                        . ' response carries it on as the request wrote it' . $order,
                    'schema' => (object) ['type' => 'string', 'pattern' => '^' . BasicLanguageRange::GRAMMAR . '$'],
                ]);
        }

        return $parameters;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function languageReferences(): array
    {
        return array_map(static function (LanguageSourceMetadata $source): array {
            return ['$ref' => self::PARAMETERS_REFERENCE . self::languageParameterName($source)];
        }, $this->api->languageSources->sources);
    }

    /**
     * the part of a 400 a language query parameter adds, null where the API declares none
     */
    public function languageFault(): ?string
    {
        $names = $this->api->languageSources->queryParameters();

        if ($names === []) {
            return null;
        }

        return sprintf(
            'the query parameter %s that decides its language holds no basic language range',
            self::listing($names, 'or'),
        );
    }

    /**
     * a source as a text names it: `the query parameter uiLocale`, or `Accept-Language`
     */
    public static function sourceName(LanguageSourceMetadata $source): string
    {
        return $source->queryParameter === null
            ? FieldName::ACCEPT_LANGUAGE
            : 'the query parameter ' . $source->queryParameter;
    }

    /**
     * @param non-empty-list<string> $items
     */
    public static function listing(array $items, string $conjunction): string
    {
        $last = array_pop($items);

        return $items === [] ? $last : implode(', ', $items) . ' ' . $conjunction . ' ' . $last;
    }

    /**
     * every type gets a filter object, whatever its own routes read:
     * a relationship condition of another type references it
     *
     * @return array<string, stdClass>
     */
    public function schemas(): array
    {
        $schemas = [];

        foreach ($this->api->resources->all() as $resource) {
            $schemas[self::filterName($resource->type)] = $this->filterSchema($resource);

            if ($resource->id->operators !== []) {
                $schemas[self::conditionName($resource->type, FieldPath::id()->name())] = $this->conditionSchema(
                    $resource,
                    null,
                );
            }

            foreach ($resource->attributes as $attribute) {
                if ($attribute->operators === []) {
                    continue;
                }

                $schemas[self::conditionName($resource->type, $attribute->name)] = $this->conditionSchema(
                    $resource,
                    $attribute,
                );
            }
        }

        return $schemas;
    }

    /**
     * a related route reads what the collection of the related type reads,
     * with the page and the default sort of the relationship.
     * a relationship route writes its include and fields paths from the declaring type.
     * a write also offers the fields of its monitor type, which its 202 carries;
     * a fetch kind never declares one
     *
     * @return list<array<string, mixed>>
     *
     * @throws LogicException when a kind of a relationship route comes without its relationship
     */
    private function familyParameters(
        ParameterFamily $family,
        OperationKind $kind,
        ResourceMetadata $resource,
        ?RelationshipMetadata $relationship,
    ): array {
        $primary = $kind === OperationKind::FetchRelated
            ? $this->api->resources->get(self::declared($relationship, $kind)->relatedType)
            : $resource;
        $relationshipRoute = $kind->targetsRelationship() && $kind !== OperationKind::FetchRelated;

        return match ($family) {
            ParameterFamily::Filter => $this->filterParameters($primary),
            ParameterFamily::Sort => $this->sortParameters(
                $primary,
                $kind === OperationKind::FetchRelated
                    ? self::declared($relationship, $kind)->defaultSort
                    : $resource->defaultSort,
                'route',
            ),
            ParameterFamily::Page => $this->pageParameters(
                $kind->targetsRelationship()
                    ? $this->api->resources->relationshipPaginationPolicy(
                        $resource->type,
                        self::declared($relationship, $kind)->name,
                    )
                    : $resource->paginationPolicy,
            ),
            ParameterFamily::Include => $this->includeParameters(
                $relationshipRoute
                    ? $this->includePaths($resource->type, self::declared($relationship, $kind)->name)
                    : $this->includePaths($primary->type, null),
            ),
            ParameterFamily::Fields => $this->fieldsParameters(self::withMonitorType(
                $relationshipRoute
                    ? $this->api->resources->includedTypes($resource->type, self::declared($relationship, $kind)->name)
                    : $this->responseTypes($primary->type),
                $resource,
                $kind,
            )),
        };
    }

    /**
     * @throws LogicException when there is none
     */
    private static function declared(?RelationshipMetadata $relationship, OperationKind $kind): RelationshipMetadata
    {
        return $relationship ?? throw new LogicException(sprintf(
            'a %s operation reads the parameters of a relationship route, which names its relationship',
            $kind->value,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filterParameters(ResourceMetadata $resource): array
    {
        if ($this->api->resources->filterMembers($resource->type) === []) {
            return [];
        }

        return [[
            'name' => ParameterFamily::Filter->value,
            'in' => 'query',
            'description' => 'the conditions the collection is filtered by. ' . self::NESTING
                . sprintf(
                    '. a relationship condition reaches at most %d %s deep',
                    $resource->maxIncludeDepth,
                    $resource->maxIncludeDepth === 1 ? 'relationship' : 'relationships',
                ),
            'style' => 'deepObject',
            'explode' => true,
            'schema' => DocumentSchemaBuilder::reference(self::filterName($resource->type)),
        ]];
    }

    /**
     * the declared default sort becomes the schema default, and it always matches the enum:
     * `Metadata\ResourceRegistry` refuses a default sort whose paths it cannot resolve
     *
     * @param 'endpoint'|'route' $subject what reads the sort
     *
     * @return list<array<string, mixed>>
     */
    private function sortParameters(ResourceMetadata $resource, ?Sort $defaultSort, string $subject): array
    {
        $fields = $this->sortFields($resource);

        if ($fields === []) {
            return [];
        }

        $schema = ['type' => 'array', 'items' => (object) ['type' => 'string', 'enum' => $fields]];

        if ($defaultSort !== null) {
            $schema['default'] = array_map(
                static function (SortField $field): string {
                    return $field->toString();
                },
                $defaultSort->fields(),
            );
        }

        return [[
            'name' => ParameterFamily::Sort->value,
            'in' => 'query',
            'description' => 'the order of the collection: sort fields, "The sort order for each sort field MUST be'
                . ' ascending unless it is prefixed with a minus (U+002D HYPHEN-MINUS, "-"), in which case it MUST'
                . ' be descending." [spec, Sorting]. a dot path runs through one to-one relationship, "owner.name"'
                . ' [spec, Sorting], a field twice gets 400, and a field that may be null cannot be sorted by'
                . ' under cursor pagination. the ' . $subject . ' sorts by its default sort where sort is not'
                . ' sent. the enum lists every path the ' . $subject . ' sorts by, and a path the enum does not'
                . ' list gets 400',
            'style' => 'form',
            'explode' => false,
            'schema' => (object) $schema,
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pageParameters(PaginationPolicy $policy): array
    {
        $parameters = [];

        foreach (PageParameter::cases() as $member) {
            $parameters[] = self::queryParameter(
                ParameterFamily::Page->parameter($member->nameIn($this->api->naming)),
                self::pageDescription($member, $policy),
                match ($member) {
                    PageParameter::Number => (object) ['type' => 'integer', 'minimum' => 1],
                    PageParameter::Size, PageParameter::Limit => (object) [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => $policy->maxSize,
                        'default' => $policy->defaultSize,
                    ],
                    PageParameter::Offset => (object) ['type' => 'integer', 'minimum' => 0],
                    PageParameter::After, PageParameter::Before => (object) ['type' => 'string'],
                    PageParameter::WithCount => (object) ['type' => 'boolean'],
                },
            );
        }

        return $parameters;
    }

    private static function pageDescription(PageParameter $member, PaginationPolicy $policy): string
    {
        $what = match ($member) {
            PageParameter::Number => 'the page of the collection to read, counted from 1',
            PageParameter::Offset => 'the number of resources to skip',
            PageParameter::After => 'the cursor the page starts after',
            PageParameter::Before => 'the cursor the page ends before',
            PageParameter::Size, PageParameter::Limit => sprintf(
                'the number of resources of one page of %s: at most %d, and %d where it is not sent',
                self::strategiesSizedBy($member),
                $policy->maxSize,
                $policy->defaultSize,
            ),
            PageParameter::WithCount => 'whether the document carries the total of the collection in meta.page; it is'
                . ' on for the page number and the offset strategy and off for the cursor strategy where it is not'
                . ' sent',
        };

        foreach (Strategy::cases() as $strategy) {
            if (in_array($member, $strategy->selectors(), true)) {
                return $what . sprintf(
                    '; it selects the %s strategy%s. parameters of two strategies in one request get 400,'
                    . ' one error object per conflicting parameter, and an unknown page parameter gets 400 as well',
                    self::strategyName($strategy),
                    $strategy === Strategy::Cursor ? ' [profile-cursor]' : '',
                );
            }
        }

        return $what . ($member === PageParameter::Size
            ? '. "If page[size] is provided, it MUST be a positive integer." [profile-cursor], and a request for a'
                . ' range, page[after] with page[before], reads the maximum where it is not sent [profile-cursor]'
            : '');
    }

    private static function strategiesSizedBy(PageParameter $member): string
    {
        $names = [];

        foreach (Strategy::cases() as $strategy) {
            if ($strategy->sizeParameter() === $member) {
                $names[] = self::strategyName($strategy);
            }
        }

        return 'the ' . implode(' and the ', $names) . ' strategy';
    }

    private static function strategyName(Strategy $strategy): string
    {
        return match ($strategy) {
            Strategy::PageNumber => 'page number',
            Strategy::Offset => 'offset',
            Strategy::Cursor => 'cursor',
        };
    }

    /**
     * offered even where no path is valid: the server still accepts an empty `include`,
     * which `maxItems: 0` states
     *
     * @param list<string> $paths
     *
     * @return list<array<string, mixed>>
     */
    private function includeParameters(array $paths): array
    {
        $empty = $paths === [];

        return [[
            'name' => ParameterFamily::Include->value,
            'in' => 'query',
            'description' => 'the relationship paths whose resources the document carries in included. "The value of'
                . ' the include parameter MUST be a comma-separated (U+002C COMMA, ",") list of relationship paths."'
                . ' [spec], and the document carries included whenever include is supplied, even when it holds none'
                . ($empty
                    ? '. no relationship this route reaches is includable, so the empty value is the one the route'
                        . ' accepts'
                    : ''),
            'style' => 'form',
            'explode' => false,
            'schema' => $empty
                ? (object) ['type' => 'array', 'maxItems' => 0]
                : (object) ['type' => 'array', 'items' => (object) ['type' => 'string', 'enum' => $paths]],
        ]];
    }

    /**
     * @param list<string> $types
     *
     * @return list<array<string, mixed>>
     */
    private function fieldsParameters(array $types): array
    {
        $parameters = [];

        foreach ($types as $type) {
            $parameters[] = self::queryParameter(
                ParameterFamily::Fields->parameter($type),
                sprintf(
                    'the fields of type "%s" the resource objects of the response carry; an unknown field gets 400',
                    $type,
                ),
                (object) [
                    'type' => 'array',
                    'items' => (object) ['type' => 'string', 'enum' => $this->fieldNames($type)],
                ],
            );
        }

        return $parameters;
    }

    /**
     * a condition through a relationship is written nested, `filter[owner][name]`:
     * the member of the relationship references the filter object of the related type,
     * so the schema writes no depth, which the server bounds by the include depth
     */
    private function filterSchema(ResourceMetadata $resource): stdClass
    {
        $name = self::filterName($resource->type);
        $properties = [
            Operators::ALL_OF => (object) ['type' => 'array', 'items' => DocumentSchemaBuilder::reference($name)],
            Operators::ANY_OF => (object) ['type' => 'array', 'items' => DocumentSchemaBuilder::reference($name)],
            Operators::NOT => DocumentSchemaBuilder::reference($name),
        ];

        foreach ($this->api->resources->filterMembers($resource->type) as $member) {
            $properties[$member->name] = DocumentSchemaBuilder::reference($member->relationship === null
                ? self::conditionName($resource->type, $member->name)
                : self::filterName($member->relationship->relatedType));
        }

        return (object) [
            'type' => 'object',
            'properties' => (object) $properties,
            'additionalProperties' => false,
            'description' => sprintf(
                'the conditions on a resource of type "%s": "a relationship name as a key opens a condition on the'
                . ' related type", and to-many means at least one related resource matches. a condition'
                . ' through a relationship is written nested, filter[owner][name][@eq]',
                $resource->type,
            ),
        ];
    }

    /**
     * `@not` is valid inside a field condition as well
     *
     * @param ?AttributeMetadata $attribute null for the id of the type
     *
     * @throws InvalidArgumentException when the field declares an operator the API lacks,
     *                                  which `ApiMetadata` already refuses
     */
    private function conditionSchema(ResourceMetadata $resource, ?AttributeMetadata $attribute): stdClass
    {
        $field = $attribute !== null ? $attribute->name : FieldPath::id()->name();
        $codec = $attribute !== null ? $attribute->codec : $resource->id->codec;
        $subject = $attribute !== null
            ? sprintf('attribute "%s" of type "%s"', $attribute->name, $resource->type)
            : sprintf('the id of type "%s"', $resource->type);
        $properties = [
            Operators::NOT => DocumentSchemaBuilder::reference(self::conditionName($resource->type, $field)),
        ];

        foreach ($attribute !== null ? $attribute->operators : $resource->id->operators as $name) {
            $operator = $this->api->operators[$name] ?? null;

            if ($operator === null) {
                throw new InvalidArgumentException(sprintf(
                    '%s declares operator "%s", which the table of operators of the API does not hold',
                    $subject,
                    Printable::of($name),
                ));
            }

            $schema = $operator->valueCodec($codec)->schema()->forRequest();
            $properties[$this->api->operatorName($operator)] = $schema->toObject();
        }

        return (object) [
            'type' => 'object',
            'properties' => (object) $properties,
            'additionalProperties' => false,
            'description' => sprintf(
                'the conditions on %s; an operator it does not declare gets 400',
                $subject,
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function sortFields(ResourceMetadata $resource): array
    {
        $fields = [];

        foreach ($this->sortPaths($resource) as $path) {
            $fields[] = $path;
            $fields[] = '-' . $path;
        }

        return $fields;
    }

    /**
     * the list the server resolves a sort against as well.
     * under the cursor strategy the server also refuses a nullable path,
     * which only the description of the parameter states
     *
     * @return list<string>
     */
    private function sortPaths(ResourceMetadata $resource): array
    {
        return array_map(
            static function (SortPath $path): string {
                return $path->path->toString();
            },
            $this->api->resources->sortPaths($resource->type),
        );
    }

    /**
     * @return list<string>
     */
    private function includePaths(string $type, ?string $relationship): array
    {
        return array_map(
            static function (IncludePath $path): string {
                return $path->toString();
            },
            $this->api->resources->includePaths($type, $relationship),
        );
    }

    /**
     * @return list<string>
     */
    private function responseTypes(string $primary): array
    {
        $types = [$primary];

        foreach ($this->api->resources->includedTypes($primary, null) as $type) {
            if (!in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @param list<string> $types
     *
     * @return list<string>
     */
    private static function withMonitorType(array $types, ResourceMetadata $resource, OperationKind $kind): array
    {
        $monitor = $resource->monitorTypes[$kind->value] ?? null;

        if ($monitor === null || in_array($monitor, $types, true)) {
            return $types;
        }

        $types[] = $monitor;

        return $types;
    }

    /**
     * @return list<string>
     */
    private function fieldNames(string $type): array
    {
        $resource = $this->api->resources->get($type);
        $names = [];

        foreach ($resource->attributes as $attribute) {
            $names[] = $attribute->name;
        }

        foreach ($resource->relationships as $relationship) {
            $names[] = $relationship->name;
        }

        return $names;
    }

    /**
     * a query parameter an endpoint or a generated route declares.
     * a scalar keeps the default style `form`, and any other shape is a `deepObject`;
     * the description writes out a list or a deeper nesting, as `NESTING` does for a filter
     *
     * @param string $place where it is read, such as `the endpoint "adoptPet"`
     *
     * @return array<string, mixed>
     */
    private static function declaredQueryParameter(QueryParameterMetadata $metadata, string $place): array
    {
        $schema = $metadata->codec->schema()->forRequest();
        $parameter = ['name' => $metadata->name, 'in' => 'query'];

        if ($metadata->required) {
            $parameter['required'] = true;
        }

        $description = sprintf(
            'the query parameter "%s" of %s%s',
            $metadata->name,
            $place,
            $metadata->required ? ', which every request carries' : '',
        );
        $shape = QueryText::shape($schema);

        if ($shape !== QueryText::SCALAR) {
            $parameter['style'] = 'deepObject';
            $parameter['explode'] = true;
        }

        if ($shape === QueryText::NESTED) {
            $description .= sprintf(
                '. a list is written with a bracket per item, %1$s[]=a&%1$s[]=b, and a nested object with a bracket'
                . ' per level',
                $metadata->name,
            );
        }

        $parameter['description'] = $description;
        $parameter['schema'] = $schema->toObject();

        return $parameter;
    }

    /**
     * @return array<string, mixed>
     */
    private static function queryParameter(string $name, string $description, stdClass $schema): array
    {
        return [
            'name' => $name,
            'in' => 'query',
            'description' => $description,
            'style' => 'form',
            'explode' => false,
            'schema' => $schema,
        ];
    }

    /**
     * @param non-empty-list<LanguageSourceMetadata> $sources
     */
    private static function sourceNames(array $sources, string $conjunction): string
    {
        return self::listing(array_map(self::sourceName(...), $sources), $conjunction);
    }

    /**
     * the component key: the name of the parameter or the field, and where it stands,
     * so a query parameter never takes the key of the field
     */
    private static function languageParameterName(LanguageSourceMetadata $source): string
    {
        return DocumentSchemaBuilder::componentName(
            $source->name(),
            $source->queryParameter === null ? 'header' : 'query',
        );
    }

    private static function filterName(string $type): string
    {
        return DocumentSchemaBuilder::componentName($type, 'filter');
    }

    private static function conditionName(string $type, string $attribute): string
    {
        return DocumentSchemaBuilder::componentName($type, $attribute, 'condition');
    }
}
