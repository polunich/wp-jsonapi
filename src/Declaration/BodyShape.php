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
use Polunich\WpJsonApi\Metadata\Endpoint\BodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\FormFieldMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaResponseMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the body an endpoint reads with `Endpoint::body()`:
 * a document in the JSON:API media type, read by the rules of the library,
 * each problem refused with its pointer:
 *
 * - `resource()`: a resource object of the type, read as a create of that type;
 * - `record()`: the resource object of the record bound to the parameter, read as an update of it;
 * - `toOne()`, `toMany()`: one resource identifier of the type or null, or a list of them;
 * - `meta()`: a document with top-level `meta` alone, read by the codec.
 *
 * a body in another media type is read by its own rules:
 *
 * - `json()`: a JSON text of `application/json` or a `+json` type, read by the codec;
 *   a value it refuses gets 422 with its pointer;
 * - `form()`: `application/x-www-form-urlencoded`, or `multipart/form-data` for a POST,
 *   with the fields `field()` declares and, for multipart, the files `file()` declares;
 *   a problem of a field gets its name in the `field` member of the error's meta;
 * - `bytes()`: the bytes of any other media type, as they came.
 *
 * a form is a mutable builder, as `ResourceType` is: `field()` and `file()` add to this object,
 * so a call on a line of its own still counts.
 * a type or a parameter is checked when the API is built
 */
final class BodyShape
{
    /** @var list<FormFieldMetadata> */
    private array $fields = [];

    /** @var array<string, bool> name => whether it is required */
    private array $files = [];

    /**
     * @param ?BodyShapeKind $kind  null for a body in another media type
     * @param ?Codec<mixed>  $codec the codec of a meta or a JSON body
     */
    private function __construct(
        private readonly ?BodyShapeKind $kind,
        private readonly ?string $type = null,
        private readonly ?string $parameter = null,
        private readonly ?Codec $codec = null,
        private readonly ?MediaBodyShapeKind $mediaKind = null,
        private readonly ?string $mediaType = null,
    ) {}

    public static function resource(string $type): self
    {
        return new self(BodyShapeKind::Resource, type: $type);
    }

    public static function record(string $parameter): self
    {
        return new self(BodyShapeKind::Record, parameter: $parameter);
    }

    public static function toOne(string $type): self
    {
        return new self(BodyShapeKind::ToOne, type: $type);
    }

    public static function toMany(string $type): self
    {
        return new self(BodyShapeKind::ToMany, type: $type);
    }

    /**
     * @param Codec<mixed> $codec reads the top-level `meta`,
     *                            and its schema describes it in the contract
     */
    public static function meta(Codec $codec): self
    {
        return new self(BodyShapeKind::Meta, codec: $codec);
    }

    /**
     * @param string       $mediaType `application/json` or a `+json` type
     * @param Codec<mixed> $codec     reads the value, and its schema describes it in the contract
     */
    public static function json(string $mediaType, Codec $codec): self
    {
        return new self(null, codec: $codec, mediaKind: MediaBodyShapeKind::Json, mediaType: $mediaType);
    }

    /**
     * @param string $mediaType `application/x-www-form-urlencoded`,
     *                          or `multipart/form-data` for a POST
     */
    public static function form(string $mediaType): self
    {
        return new self(null, mediaKind: MediaBodyShapeKind::Form, mediaType: $mediaType);
    }

    /**
     * @param string $mediaType any media type but JSON:API, such as `image/png`
     */
    public static function bytes(string $mediaType): self
    {
        return new self(null, mediaKind: MediaBodyShapeKind::Bytes, mediaType: $mediaType);
    }

    /**
     * a field of a form, optional unless `$required`;
     * the codec reads its text as a query value is read
     *
     * @param Codec<mixed> $codec
     *
     * @throws InvalidArgumentException when the body is no form
     */
    public function field(string $name, Codec $codec, bool $required = false): self
    {
        $this->checkForm('a field');

        $this->fields[] = new FormFieldMetadata($name, $codec, $required);

        return $this;
    }

    /**
     * a file of a `multipart/form-data` form, optional unless `$required`; the field holds one file
     *
     * @throws InvalidArgumentException when the body is no form
     */
    public function file(string $name, bool $required = false): self
    {
        $this->checkForm('a file');

        if (array_key_exists($name, $this->files)) {
            throw new InvalidArgumentException(sprintf('a form declares the file "%s" twice', Printable::of($name)));
        }

        $this->files[$name] = $required;

        return $this;
    }

    /**
     * whether the body is a JSON:API shape rather than one in another media type
     *
     * @internal
     */
    public function isJsonApi(): bool
    {
        return $this->kind !== null;
    }

    /**
     * @throws InvalidArgumentException when the text is no media type, or the body refuses it
     * @throws LogicException           when the shape is a JSON:API one
     *
     * @internal
     */
    public function buildMedia(string $owner): MediaBodyMetadata
    {
        $text = $this->mediaType ?? throw new LogicException('a JSON:API body shape reads no other media type');

        return new MediaBodyMetadata(
            MediaResponseMetadata::mediaType($text, sprintf('a body of %s', $owner)),
            $this->mediaKind ?? throw new LogicException('a body in another media type names how it is read'),
            $this->codec,
            $this->fields,
            $this->files,
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    private function checkForm(string $part): void
    {
        if ($this->mediaKind !== MediaBodyShapeKind::Form) {
            throw new InvalidArgumentException(sprintf('%s belongs to a form, and this body is none', $part));
        }
    }

    /**
     * @param array<string, string> $bindings parameter => bound type
     * @param string                $owner    the endpoint, for the message
     *
     * @throws InvalidArgumentException when the type is not declared, or the parameter is not bound
     * @throws LogicException           when the shape is a media body
     *
     * @internal
     */
    public function build(array $bindings, ResourceRegistry $resources, string $owner): BodyMetadata
    {
        $kind = $this->kind
            ?? throw new LogicException('a body in another media type is no JSON:API shape; buildMedia() builds it');
        $body = 'the body of ' . $owner;

        if ($this->parameter !== null) {
            return new BodyMetadata(
                $kind,
                Endpoint::boundType($this->parameter, $bindings, $body),
                $this->parameter,
                null,
            );
        }

        return new BodyMetadata(
            $kind,
            $this->type === null ? null : Endpoint::declaredType($this->type, $resources, $body),
            null,
            $this->codec,
        );
    }
}
