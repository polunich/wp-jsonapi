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

namespace Polunich\WpJsonApi\Document;

use stdClass;

/**
 * every object writes its members in one fixed order,
 * so a document always encodes to the same output and two responses compare member for member.
 *
 * a null or empty member is left out, an empty links object included,
 * unless the emptiness is itself the value:
 * primary data and relationship linkage, where null and `[]` say there is nothing;
 * `included`, whenever the document carries it;
 * `meta` of a meta document, the one member that document has;
 * and a null link, which says the link does not exist.
 *
 * attributes, relationships, links and meta are written as a `stdClass`,
 * because their names are data and the specification requires an object:
 * `json_encode()` writes an array with the keys `0`, `1` ... as a JSON array,
 * while `0` and `1` are legal member names.
 * an object whose member names the encoder fixes stays an array, since its keys never form a list.
 * the cast is one level deep: values inside attributes and meta keep the shape they arrive in
 *
 * @internal
 */
final readonly class DocumentEncoder
{
    /**
     * @return array<string, mixed>
     */
    public function encode(AtomicResultsDocument|DataDocument|ErrorDocument|MetaDocument $document): array
    {
        if ($document instanceof DataDocument) {
            return $this->encodeDataDocument($document);
        }

        if ($document instanceof MetaDocument) {
            return [
                Member::JSONAPI => $this->encodeJsonApiObject($document->jsonApi),
                Member::META => (object) $document->meta,
            ];
        }

        if ($document instanceof ErrorDocument) {
            return $this->encodeErrorDocument($document);
        }

        return $this->encodeAtomicResultsDocument($document);
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeDataDocument(DataDocument $document): array
    {
        $encoded = [Member::JSONAPI => $this->encodeJsonApiObject($document->jsonApi)];

        if (!$document->links->isEmpty()) {
            $encoded[Member::LINKS] = $this->encodeLinks($document->links);
        }

        $encoded[Member::DATA] = $this->encodeData($document->data);

        if ($document->included !== null) {
            $included = [];

            foreach ($document->included->all() as $resource) {
                $included[] = $this->encodeResourceObject($resource);
            }

            $encoded[Member::INCLUDED] = $included;
        }

        if ($document->meta !== null && $document->meta !== []) {
            $encoded[Member::META] = (object) $document->meta;
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeErrorDocument(ErrorDocument $document): array
    {
        $errors = [];

        foreach ($document->errors as $error) {
            $errors[] = $this->encodeErrorObject($error);
        }

        $encoded = [
            Member::JSONAPI => $this->encodeJsonApiObject($document->jsonApi),
            Member::ERRORS => $errors,
        ];

        if ($document->meta !== null && $document->meta !== []) {
            $encoded[Member::META] = (object) $document->meta;
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeAtomicResultsDocument(AtomicResultsDocument $document): array
    {
        $results = [];

        foreach ($document->results as $result) {
            $results[] = $result === null
                ? new stdClass()
                : [Member::DATA => $this->encodeResourceObject($result)];
        }

        $encoded = [
            Member::JSONAPI => $this->encodeJsonApiObject($document->jsonApi),
            Member::ATOMIC_RESULTS => $results,
        ];

        if ($document->meta !== null && $document->meta !== []) {
            $encoded[Member::META] = (object) $document->meta;
        }

        return $encoded;
    }

    /**
     * @param (ResourceIdentifierObject|ResourceObject
     *     |list<ResourceIdentifierObject>|list<ResourceObject>|null) $data
     *
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    private function encodeData(ResourceIdentifierObject|ResourceObject|array|null $data): ?array
    {
        if ($data === null) {
            return null;
        }

        if ($data instanceof ResourceObject) {
            return $this->encodeResourceObject($data);
        }

        if ($data instanceof ResourceIdentifierObject) {
            return $this->encodeResourceIdentifierObject($data);
        }

        $encoded = [];

        foreach ($data as $item) {
            $encoded[] = $item instanceof ResourceObject
                ? $this->encodeResourceObject($item)
                : $this->encodeResourceIdentifierObject($item);
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeResourceObject(ResourceObject $resource): array
    {
        $encoded = [
            Member::TYPE => $resource->type,
            Member::ID => $resource->id,
        ];

        if ($resource->attributes !== []) {
            $encoded[Member::ATTRIBUTES] = (object) $resource->attributes;
        }

        if ($resource->relationships !== []) {
            $relationships = [];

            foreach ($resource->relationships as $name => $relationship) {
                $relationships[$name] = $this->encodeRelationshipObject($relationship);
            }

            $encoded[Member::RELATIONSHIPS] = (object) $relationships;
        }

        if (!$resource->links->isEmpty()) {
            $encoded[Member::LINKS] = $this->encodeLinks($resource->links);
        }

        if ($resource->meta !== null && $resource->meta !== []) {
            $encoded[Member::META] = (object) $resource->meta;
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeResourceIdentifierObject(ResourceIdentifierObject $identifier): array
    {
        $encoded = [
            Member::TYPE => $identifier->type,
            Member::ID => $identifier->id,
        ];

        if ($identifier->meta !== null && $identifier->meta !== []) {
            $encoded[Member::META] = (object) $identifier->meta;
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeRelationshipObject(RelationshipObject $relationship): array
    {
        $encoded = [];

        if (!$relationship->links->isEmpty()) {
            $encoded[Member::LINKS] = $this->encodeLinks($relationship->links);
        }

        if ($relationship->hasLinkage()) {
            $encoded[Member::DATA] = $this->encodeData($relationship->linkage());
        }

        if ($relationship->meta !== null && $relationship->meta !== []) {
            $encoded[Member::META] = (object) $relationship->meta;
        }

        return $encoded;
    }

    private function encodeLinks(Links $links): stdClass
    {
        $encoded = [];

        foreach ($links->all() as $name => $link) {
            $encoded[$name] = $link === null ? null : $this->encodeLink($link);
        }

        return (object) $encoded;
    }

    /**
     * @return array<string, mixed>|string
     */
    private function encodeLink(Link $link): array|string
    {
        if ($link->isHrefOnly()) {
            return $link->href;
        }

        $encoded = [Member::HREF => $link->href];

        if ($link->rel !== null) {
            $encoded[Member::REL] = $link->rel;
        }

        if ($link->describedby !== null) {
            $encoded[Member::DESCRIBEDBY] = $this->encodeLink($link->describedby);
        }

        if ($link->title !== null) {
            $encoded[Member::TITLE] = $link->title;
        }

        if ($link->type !== null) {
            $encoded[Member::TYPE] = $link->type;
        }

        if ($link->hreflang !== null && $link->hreflang !== []) {
            $encoded[Member::HREFLANG] = $link->hreflang;
        }

        if ($link->meta !== null && $link->meta !== []) {
            $encoded[Member::META] = (object) $link->meta;
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeJsonApiObject(JsonApiObject $jsonApi): array
    {
        $encoded = [Member::VERSION => $jsonApi->version];

        if ($jsonApi->ext !== []) {
            $encoded[Member::EXT] = $jsonApi->ext;
        }

        if ($jsonApi->profile !== []) {
            $encoded[Member::PROFILE] = $jsonApi->profile;
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeErrorObject(ErrorObject $error): array
    {
        $encoded = [
            Member::STATUS => $error->status,
            Member::CODE => $error->code,
            Member::TITLE => $error->title,
        ];

        if ($error->detail !== null) {
            $encoded[Member::DETAIL] = $error->detail;
        }

        if ($error->source !== null) {
            $encoded[Member::SOURCE] = $this->encodeErrorSource($error->source);
        }

        if ($error->links !== null && !$error->links->isEmpty()) {
            $encoded[Member::LINKS] = $this->encodeLinks($error->links);
        }

        if ($error->meta !== null && $error->meta !== []) {
            $encoded[Member::META] = (object) $error->meta;
        }

        return $encoded;
    }

    /**
     * @return array<string, string>
     */
    private function encodeErrorSource(ErrorSource $source): array
    {
        $encoded = [];

        if ($source->pointer !== null) {
            $encoded[Member::POINTER] = $source->pointer;
        }

        if ($source->parameter !== null) {
            $encoded[Member::PARAMETER] = $source->parameter;
        }

        if ($source->header !== null) {
            $encoded[Member::HEADER] = $source->header;
        }

        return $encoded;
    }
}
