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
use LogicException;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Text\Printable;

/**
 * one response an endpoint declares with `Endpoint::responds()`. the shape fixes the status:
 * 201 for `created()`, 202 for `accepted()`, 204 for `noContent()`, 200 for every other one.
 *
 * - `resource()`, `collection()`: any resource or a list of resources of the type,
 *   with `include` and `fields` applied; a collection has no pages;
 * - `page()`: a page of the resources of the type, GET alone.
 *   the library reads `filter`, `sort` and `page` into `Endpoint\Request::$collectionQuery`,
 *   the handler reads the records it selects, and the library pages them
 *   with pagination links to the URL of the endpoint, as on the collection route;
 * - `record()`: the record bound to the parameter, as the handler leaves it.
 *   its entity tag, where its type declares a version,
 *   is the one the preconditions of the endpoint compare, and the response carries it;
 * - `created()`: the resource the request created,
 *   linked and named in `Location` where its type keeps the fetch of its resources;
 * - `related()`, `linkage()`: the related resources, or the linkage,
 *   of a relationship of the bound record;
 * - `meta()`: a document with top-level `meta` alone, written by the codec;
 * - `accepted()`: the monitor of accepted work, a resource of the monitor type;
 * - `noContent()`: no document;
 * - `content()`: 200 with bytes of another media type, such as `text/csv`,
 *   which the handler hands over as `Http\Content`;
 * - `json()`: 200 with a JSON value of `application/json` or a `+json` type, written by the codec.
 *
 * a type, a parameter or a relationship is checked when the API is built
 */
final readonly class ResponseShape
{
    /**
     * @param ?ResponseShapeKind $kind  null for a media response
     * @param ?Codec<mixed>    $codec the codec of a meta or a JSON response
     */
    private function __construct(
        private ?ResponseShapeKind $kind,
        private ?string $type = null,
        private ?string $parameter = null,
        private ?string $relationship = null,
        private ?Codec $codec = null,
        private ?string $mediaType = null,
    ) {}

    public static function resource(string $type): self
    {
        return new self(ResponseShapeKind::Resource, type: $type);
    }

    public static function record(string $parameter): self
    {
        return new self(ResponseShapeKind::Record, parameter: $parameter);
    }

    public static function created(string $type): self
    {
        return new self(ResponseShapeKind::Created, type: $type);
    }

    public static function collection(string $type): self
    {
        return new self(ResponseShapeKind::Collection, type: $type);
    }

    public static function page(string $type): self
    {
        return new self(ResponseShapeKind::Page, type: $type);
    }

    public static function related(string $parameter, string $relationship): self
    {
        return new self(ResponseShapeKind::Related, parameter: $parameter, relationship: $relationship);
    }

    public static function linkage(string $parameter, string $relationship): self
    {
        return new self(ResponseShapeKind::Linkage, parameter: $parameter, relationship: $relationship);
    }

    /**
     * @param Codec<mixed> $codec writes the value the handler returns as the top-level `meta`,
     *                            and its schema describes it in the contract
     */
    public static function meta(Codec $codec): self
    {
        return new self(ResponseShapeKind::Meta, codec: $codec);
    }

    public static function accepted(string $monitorType): self
    {
        return new self(ResponseShapeKind::Accepted, type: $monitorType);
    }

    public static function noContent(): self
    {
        return new self(ResponseShapeKind::NoContent);
    }

    /**
     * @param string $mediaType such as `text/csv` or `application/pdf`,
     *                          with the parameters the content states
     */
    public static function content(string $mediaType): self
    {
        return new self(null, mediaType: $mediaType);
    }

    /**
     * @param string       $mediaType `application/json` or a `+json` type
     * @param Codec<mixed> $codec     writes the value the handler returns,
     *                                and its schema describes it in the contract
     */
    public static function json(string $mediaType, Codec $codec): self
    {
        return new self(null, codec: $codec, mediaType: $mediaType);
    }

    /**
     * @internal
     */
    public function status(): int
    {
        return $this->kind?->status() ?? 200;
    }

    /**
     * whether the response is a JSON:API shape rather than a media response
     *
     * @internal
     */
    public function isJsonApi(): bool
    {
        return $this->kind !== null;
    }

    /**
     * @throws InvalidArgumentException when the text is no media type,
     *                                  or a media response refuses it
     * @throws LogicException           when the shape is a JSON:API one
     *
     * @internal
     */
    public function buildMedia(string $owner): MediaResponseMetadata
    {
        $text = $this->mediaType ?? throw new LogicException('a JSON:API response shape declares no media response');

        return new MediaResponseMetadata(
            MediaResponseMetadata::mediaType($text, sprintf('the response of 200 of %s', $owner)),
            $this->codec,
        );
    }

    /**
     * @param array<string, string> $bindings parameter => bound type
     * @param string                $owner    the endpoint, for the message
     *
     * @throws InvalidArgumentException when a type is not declared, a parameter is not bound,
     *                                  or the bound type declares no such relationship
     * @throws LogicException           when the shape is a media response
     *
     * @internal
     */
    public function build(array $bindings, ResourceRegistry $resources, string $owner): ResponseMetadata
    {
        $kind = $this->kind
            ?? throw new LogicException('a media response is no JSON:API response; buildMedia() builds it');
        $part = sprintf('the response of %d of %s', $this->status(), $owner);

        if ($this->parameter === null) {
            return new ResponseMetadata(
                $kind,
                $this->type === null ? null : Endpoint::declaredType($this->type, $resources, $part),
                null,
                null,
                null,
                $this->codec,
            );
        }

        $bound = Endpoint::boundType($this->parameter, $bindings, $part);

        if ($this->relationship === null) {
            return new ResponseMetadata($kind, $bound, $this->parameter, null, null, null);
        }

        $relationship = $resources->get($bound)->relationships[$this->relationship]
            ?? throw new InvalidArgumentException(sprintf(
                '%s names the relationship "%s" of the record bound to "%s",'
                . ' and type "%s" declares no such relationship',
                $part,
                Printable::of($this->relationship),
                $this->parameter,
                $bound,
            ));

        return new ResponseMetadata(
            $kind,
            $relationship->relatedType,
            $this->parameter,
            $relationship->name,
            $relationship->cardinality,
            null,
        );
    }
}
