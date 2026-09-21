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

namespace Polunich\WpJsonApi\Endpoint;

use InvalidArgumentException;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Store\Slice;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the kind fixes the status, and the endpoint declares the shape of each status it responds with;
 * a response the endpoint does not declare is a fault of the handler, and the client gets 500.
 *
 * records are the project's own objects, of the type the declaration names:
 * the library reads each through the accessors of that type and writes the document.
 * a content or a JSON value names its media type, one the endpoint declares for 200.
 * a 200 response is in the representation `Accept` picked, `Request::$mediaType`,
 * wherever the endpoint's 200 offers that one
 */
final readonly class Response
{
    /**
     * @param ?string $mediaType      the media type of a content or a JSON value
     * @param ?string $attachmentName the file name a client saves a content under
     */
    private function __construct(
        private ResponseKind $kind,
        private mixed $payload,
        private ?string $mediaType = null,
        private ?string $attachmentName = null,
    ) {}

    /**
     * 200 with one resource as primary data;
     * null only where the declared response is the related resource of a to-one relationship,
     * the one primary resource that may be empty
     */
    public static function resource(?object $record): self
    {
        return new self(ResponseKind::Resource, $record);
    }

    /**
     * 201 with the resource the request created
     */
    public static function created(object $record): self
    {
        return new self(ResponseKind::Created, $record);
    }

    /**
     * 200 with a collection of resources as primary data, in the order given
     *
     * @param iterable<mixed> $records
     *
     * @throws InvalidArgumentException when an item is no object
     */
    public static function collection(iterable $records): self
    {
        return new self(ResponseKind::Collection, self::records($records));
    }

    /**
     * 200 with a page of a collection as primary data:
     * the records `Request::$collectionQuery` selects, in its order and within its window,
     * as a `Store\CollectionReader` returns them.
     * the library trims the record past the page and writes the pagination links from it
     *
     * @template TRecord of object
     *
     * @param Slice<TRecord> $slice
     */
    public static function page(Slice $slice): self
    {
        return new self(ResponseKind::Page, $slice);
    }

    /**
     * 200 with the linkage of a relationship as primary data:
     * the related record or null for a to-one relationship, the related records for a to-many one
     *
     * @param object|iterable<mixed>|null $related
     *
     * @throws InvalidArgumentException when an item of a list is no object
     */
    public static function linkage(object|iterable|null $related): self
    {
        return new self(ResponseKind::Linkage, is_iterable($related) ? self::records($related) : $related);
    }

    /**
     * 200 with a document of top-level meta alone, encoded by the codec the endpoint declares
     */
    public static function meta(mixed $value): self
    {
        return new self(ResponseKind::Meta, $value);
    }

    /**
     * 202 with the record that monitors the accepted work
     */
    public static function accepted(object $monitor): self
    {
        return new self(ResponseKind::Accepted, $monitor);
    }

    /**
     * 204 with no document
     */
    public static function noContent(): self
    {
        return new self(ResponseKind::NoContent, null);
    }

    /**
     * 200 with content of another media type.
     * an attachment name adds `Content-Disposition: attachment` with that file name,
     * which asks the client to save the content rather than display it (RFC 6266, section 4.2)
     *
     * @param string $mediaType one the endpoint declares with `ResponseShape::content()`
     *
     * @throws InvalidArgumentException when the attachment name is empty, no UTF-8,
     *                                  or holds a control character
     */
    public static function content(Content $content, string $mediaType, ?string $attachmentName = null): self
    {
        if (
            $attachmentName !== null
            && ($attachmentName === '' || preg_match(
                '//u',
                $attachmentName,
            ) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $attachmentName) === 1)
        ) {
            throw new InvalidArgumentException(sprintf(
                'an attachment name is a file name in UTF-8, neither empty nor holding a control character, got "%s"',
                Printable::of($attachmentName),
            ));
        }

        return new self(ResponseKind::Content, $content, $mediaType, $attachmentName);
    }

    /**
     * 200 with a JSON value,
     * written by the codec the endpoint declares with `ResponseShape::json()`
     *
     * @param string $mediaType `application/json` or a `+json` type the endpoint declares
     */
    public static function json(mixed $value, string $mediaType): self
    {
        return new self(ResponseKind::Json, $value, $mediaType);
    }

    /**
     * @internal
     */
    public function mediaType(): ?string
    {
        return $this->mediaType;
    }

    /**
     * @internal
     */
    public function attachmentName(): ?string
    {
        return $this->attachmentName;
    }

    /**
     * @internal
     */
    public function kind(): ResponseKind
    {
        return $this->kind;
    }

    /**
     * the record, the records, the slice, the meta value, the content or the JSON value
     * the response carries
     *
     * @internal
     */
    public function payload(): mixed
    {
        return $this->payload;
    }

    /**
     * @param iterable<mixed> $records
     *
     * @return list<object>
     *
     * @throws InvalidArgumentException when an item is no object
     */
    private static function records(iterable $records): array
    {
        $list = [];

        foreach ($records as $record) {
            if (!is_object($record)) {
                throw new InvalidArgumentException(sprintf(
                    'a response lists records, the objects of the project a type reads, and item %d is %s',
                    count($list),
                    get_debug_type($record),
                ));
            }

            $list[] = $record;
        }

        return $list;
    }
}
