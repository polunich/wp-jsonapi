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
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\SegmentCodec;
use Polunich\WpJsonApi\Http\Method;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Routing\PathTemplate;
use Polunich\WpJsonApi\Routing\SegmentPattern;
use Polunich\WpJsonApi\Text\Printable;

/**
 * declares one endpoint of the API beyond its method, path and handler.
 *
 * the path names each parameter in braces, as a whole segment,
 * and each parameter takes a codec with `pathParameter()` or a type with `bind()`.
 * a literal segment holds letters, digits, `-`, `_` and `~`.
 *
 * the builder is mutable like `ResourceType`, so a call on a line of its own still counts,
 * and each part is written once.
 * it is read when the API is built, so a change made afterwards does not reach that API.
 * a name, a permission and at least one response are required;
 * the rules that need the resource types are checked when the API is built
 */
final class Endpoint
{
    private ImplementationReference $handler;

    private ?string $name = null;

    /** @var array<string, SegmentCodec> */
    private array $codecs = [];

    /** @var array<string, string> parameter => type */
    private array $bindings = [];

    /** @var list<QueryParameterMetadata> */
    private array $queryParameters = [];

    private ?BodyShape $body = null;

    /** @var list<BodyShape> the bodies in other media types, in the order declared */
    private array $mediaBodies = [];

    /** @var array<int, ResponseShape> status => response */
    private array $responses = [];

    /** @var list<ResponseShape> the media responses of 200, in the order declared */
    private array $mediaResponses = [];

    /** @var ?int how many media responses were declared before the first JSON:API response */
    private ?int $jsonApiRank = null;

    private ?ImplementationReference $permission = null;

    private ?string $tag = null;

    private ?string $description = null;

    private ?string $preconditionParameter = null;

    private bool $leavesUnchanged = false;

    /** @var ?list<SecurityScheme> */
    private ?array $securitySchemes = null;

    private ?Sort $defaultSort = null;

    private ?PaginationPolicy $paginationPolicy = null;

    /**
     * @internal created by the builder of the API
     *
     * @param Implementation $handler binds an `Endpoint\Handler`
     */
    public function __construct(
        private string $method,
        private string $path,
        Implementation $handler,
    ) {
        $this->handler = $handler->build();
    }

    /**
     * the operationId of the endpoint in the contract,
     * a letter or `_`, then letters, digits and `_`, unique among the endpoints of the API
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
     * @throws InvalidArgumentException when the parameter already has a codec or a binding,
     *                                  or the pattern of the codec is one a route cannot hold
     */
    public function pathParameter(string $name, SegmentCodec $codec): self
    {
        $this->checkParameterFree($name);
        SegmentPattern::check($codec, sprintf('the parameter "%s" of %s', Printable::of($name), $this->owner()));

        $this->codecs[$name] = $codec;

        return $this;
    }

    /**
     * binds a path parameter to a type: the parameter takes the id codec of the type,
     * and the record is loaded through the loader of the type before the handler runs;
     * a request for a missing or hidden record gets 404
     *
     * @throws InvalidArgumentException when the parameter already has a codec or a binding
     */
    public function bind(string $parameter, string $type): self
    {
        $this->checkParameterFree($parameter);

        $this->bindings[$parameter] = $type;

        return $this;
    }

    /**
     * a query parameter the endpoint reads, optional unless `$required`;
     * a request without a required one gets 400.
     * the codec reads the text of the query, and the arrays of a family written with brackets.
     * a name of a-z alone, such as `format`, fails the API build
     * unless the API turns off its strict rule of query parameter names
     *
     * @param Codec<mixed> $codec
     *
     * @throws InvalidArgumentException when the name is no legal member name,
     *                                  names a family of the specification, is one WordPress reads,
     *                                  or is declared twice
     */
    public function queryParameter(string $name, Codec $codec, bool $required = false): self
    {
        $this->queryParameters = QueryParameters::added(
            $this->queryParameters,
            new QueryParameterMetadata($name, $codec, $required),
            $this->owner(),
        );

        return $this;
    }

    /**
     * a body the endpoint reads, one per media type:
     * a JSON:API shape once, and bodies in other media types.
     * the `Content-Type` of a request picks one,
     * and content of a media type the endpoint reads no body of gets 415
     *
     * @throws InvalidArgumentException when a JSON:API body is written twice
     */
    public function body(BodyShape $body): self
    {
        if (!$body->isJsonApi()) {
            $this->mediaBodies[] = $body;

            return $this;
        }

        if ($this->body !== null) {
            throw WrittenTwice::refusal($this->owner(), 'a JSON:API body');
        }

        $this->body = $body;

        return $this;
    }

    /**
     * a response the endpoint gives: one JSON:API shape per status,
     * and any number of media responses of 200,
     * among which `Accept` picks in the order they are declared
     *
     * @throws InvalidArgumentException when a JSON:API response of that status is written twice
     */
    public function responds(ResponseShape $response): self
    {
        if (!$response->isJsonApi()) {
            $this->mediaResponses[] = $response;

            return $this;
        }

        $status = $response->status();

        if (array_key_exists($status, $this->responses)) {
            throw WrittenTwice::refusal($this->owner(), sprintf('a response of %d', $status));
        }

        $this->responses[$status] = $response;
        $this->jsonApiRank ??= count($this->mediaResponses);

        return $this;
    }

    /**
     * the permission of the endpoint, which every endpoint binds: an `Access\EndpointPermission`,
     * such as `Access\AllowAny` for a public one
     *
     * @throws InvalidArgumentException when a permission is written twice
     */
    public function permission(Implementation $implementation): self
    {
        if ($this->permission !== null) {
            throw WrittenTwice::refusal($this->owner(), 'a permission');
        }

        $this->permission = $implementation->build();

        return $this;
    }

    /**
     * the tag of the endpoint in the contract;
     * without one the operation takes the type of its first bound parameter,
     * and an endpoint that binds none takes no tag
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
     * the description of the endpoint in the contract
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
     * the schemes the contract lists for the operation of the endpoint, in place of the API's;
     * with none, the operation lists only the empty requirement, anonymous access.
     * WordPress authenticates as before, and the permission decides:
     * the schemes tell a client, and a client generated from the contract, where credentials go
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
     * the order of the page the endpoint responds with when a request sends no `sort`,
     * in the sort syntax of the specification, such as `-listedAt,name`;
     * without one the page takes the default sort of its type, else it is sorted by id.
     * the API build checks that each path is sortable and never null
     *
     * @throws InvalidArgumentException when a default sort is written twice,
     *                                  or the text is no sort
     */
    public function defaultSort(string $sort): self
    {
        if ($this->defaultSort !== null) {
            throw WrittenTwice::refusal($this->owner(), 'a default sort');
        }

        $this->defaultSort = Sort::fromString($sort);

        return $this;
    }

    /**
     * the page sizes of the page the endpoint responds with;
     * without this call they are those of the type of the page
     *
     * @param int $defaultSize used when a request names no size; at least 1
     * @param int $maxSize     a request with a larger size gets 400 with the maximum in `meta.page`
     *
     * @throws InvalidArgumentException when a pagination policy is written twice,
     *                                  or the default size is below 1 or above the maximum
     */
    public function pagination(int $defaultSize, int $maxSize): self
    {
        if ($this->paginationPolicy !== null) {
            throw WrittenTwice::refusal($this->owner(), 'a pagination policy');
        }

        $this->paginationPolicy = new PaginationPolicy($defaultSize, $maxSize);

        return $this;
    }

    /**
     * names the bound record the preconditions of a write read,
     * where the endpoint binds two or more records of versioned types
     *
     * @throws InvalidArgumentException when the record is named twice
     */
    public function preconditionRecord(string $parameter): self
    {
        if ($this->preconditionParameter !== null) {
            throw WrittenTwice::refusal($this->owner(), 'the record of its preconditions');
        }

        $this->preconditionParameter = $parameter;

        return $this;
    }

    /**
     * states that the write leaves its precondition record unchanged,
     * so a request without `If-Match` does not get 428
     * where the type of that record requires preconditions
     *
     * @throws InvalidArgumentException when it is written twice
     */
    public function leavesUnchanged(): self
    {
        if ($this->leavesUnchanged) {
            throw WrittenTwice::refusal($this->owner(), 'that it leaves its record unchanged');
        }

        $this->leavesUnchanged = true;

        return $this;
    }

    /**
     * @throws InvalidArgumentException when a part is missing or refused,
     *                                  or the declaration disagrees with the resource types
     *
     * @internal
     */
    public function build(ResourceRegistry $resources): EndpointMetadata
    {
        $name = $this->name ?? throw new InvalidArgumentException(sprintf(
            '%s declares no name, and its name is the operationId of the contract',
            $this->owner(),
        ));
        $owner = $this->owner();
        $codecs = $this->codecs;

        foreach ($this->bindings as $parameter => $type) {
            $codecs[$parameter] = $resources->get(self::declaredType($type, $resources, sprintf(
                'the parameter "%s" of %s',
                $parameter,
                $owner,
            )))->id->codec;
        }

        $responses = [];

        foreach ($this->responses as $status => $response) {
            $responses[$status] = $response->build($this->bindings, $resources, $owner);
        }

        ksort($responses);
        $this->checkIncludeRoot($responses);

        return new EndpointMetadata(
            $name,
            $this->method,
            PathTemplate::written($this->path, $codecs, $owner),
            $this->bindings,
            $this->queryParameters,
            $this->body?->build($this->bindings, $resources, $owner),
            $responses,
            $this->handler,
            $this->permission ?? throw new InvalidArgumentException(sprintf(
                '%s declares no permission, and every endpoint binds one; a public endpoint binds Access\AllowAny',
                $owner,
            )),
            $this->tag,
            $this->description,
            $this->derivedPreconditionParameter($responses, $resources),
            $this->leavesUnchanged,
            array_map(static function (ResponseShape $shape) use ($owner) {
                return $shape->buildMedia($owner);
            }, $this->mediaResponses),
            $this->jsonApiRank ?? 0,
            array_map(static function (BodyShape $body) use ($owner) {
                return $body->buildMedia($owner);
            }, $this->mediaBodies),
            $this->securitySchemes,
            $this->defaultSort,
            $this->paginationPolicy,
        );
    }

    /**
     * @throws InvalidArgumentException when the type is not declared
     *
     * @internal
     */
    public static function declaredType(string $type, ResourceRegistry $resources, string $part): string
    {
        if (!$resources->has($type)) {
            throw new InvalidArgumentException(sprintf(
                '%s names type "%s", which is not declared',
                $part,
                Printable::of($type),
            ));
        }

        return $type;
    }

    /**
     * @param array<string, string> $bindings
     *
     * @throws InvalidArgumentException when the parameter is bound to no type
     *
     * @internal
     */
    public static function boundType(string $parameter, array $bindings, string $part): string
    {
        return $bindings[$parameter] ?? throw new InvalidArgumentException(sprintf(
            '%s names the parameter "%s", which is bound to no type',
            $part,
            Printable::of($parameter),
        ));
    }

    /**
     * `include` and `fields` of a request are read against one place of the graph,
     * so every response that carries resources starts there: a type, or a relationship of a type
     *
     * @param array<int, ResponseMetadata> $responses
     *
     * @throws InvalidArgumentException when two responses start at different places
     */
    private function checkIncludeRoot(array $responses): void
    {
        $root = null;

        foreach ($responses as $status => $response) {
            if (!$response->kind->carriesResources()) {
                continue;
            }

            $place = $response->kind === ResponseShapeKind::Linkage
                ? sprintf(
                    'relationship "%s" of type "%s"',
                    (string) $response->relationship,
                    $this->bindings[(string) $response->parameter] ?? '',
                )
                : sprintf('type "%s"', (string) $response->type);

            if ($root !== null && $root !== $place) {
                throw new InvalidArgumentException(sprintf(
                    '%s responds with resources of %s and, with %d, of %s, and one request reads its include and'
                    . ' fields parameters against one place',
                    $this->owner(),
                    $root,
                    $status,
                    $place,
                ));
            }

            $root = $place;
        }
    }

    /**
     * GET and HEAD read the record their response of 200 names;
     * a write reads the one bound record of a versioned type, or the one it names
     *
     * @param array<int, ResponseMetadata> $responses
     *
     * @throws InvalidArgumentException when a named record is none of a versioned type,
     *                                  a safe endpoint names one,
     *                                  or a write binds two or more and names none
     */
    private function derivedPreconditionParameter(array $responses, ResourceRegistry $resources): ?string
    {
        $owner = $this->owner();

        if (Method::isSafe($this->method)) {
            if ($this->preconditionParameter !== null) {
                throw new InvalidArgumentException(sprintf(
                    '%s responds to GET and names the record of its preconditions, which for a safe method is the'
                    . ' record its response of 200 names',
                    $owner,
                ));
            }

            $response = $responses[200] ?? null;

            return $response !== null
                && $response->kind === ResponseShapeKind::Record
                && $resources->get((string) $response->type)->versionPolicy !== null
                ? $response->parameter
                : null;
        }

        $versioned = [];

        foreach ($this->bindings as $parameter => $type) {
            if ($resources->get($type)->versionPolicy !== null) {
                $versioned[] = $parameter;
            }
        }

        if ($this->preconditionParameter !== null) {
            if (!in_array($this->preconditionParameter, $versioned, true)) {
                throw new InvalidArgumentException(sprintf(
                    '%s names "%s" the record of its preconditions, and that is no parameter bound to a type that'
                    . ' declares a version',
                    $owner,
                    Printable::of($this->preconditionParameter),
                ));
            }

            return $this->preconditionParameter;
        }

        if (count($versioned) > 1) {
            throw new InvalidArgumentException(sprintf(
                '%s binds the records "%s" of versioned types, and its preconditions read one of them: name it with'
                . ' preconditionRecord()',
                $owner,
                implode('", "', $versioned),
            ));
        }

        return $versioned[0] ?? null;
    }

    /**
     * @throws InvalidArgumentException when the parameter already has a codec or a binding
     */
    private function checkParameterFree(string $name): void
    {
        if (array_key_exists($name, $this->codecs) || array_key_exists($name, $this->bindings)) {
            throw WrittenTwice::refusal($this->owner(), sprintf(
                'the parameter "%s", with a codec or a binding,',
                Printable::of($name),
            ));
        }
    }

    /**
     * the name is escaped: `build()` has not checked it yet
     */
    private function owner(): string
    {
        return $this->name === null
            ? sprintf('the endpoint %s %s', $this->method, Printable::of($this->path))
            : sprintf('the endpoint "%s"', Printable::of($this->name));
    }
}
