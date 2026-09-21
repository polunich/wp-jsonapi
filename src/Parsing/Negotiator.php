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

namespace Polunich\WpJsonApi\Parsing;

use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\NotAcceptableException;
use Polunich\WpJsonApi\Error\UnsupportedMediaTypeException;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\Method;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Routing\RouteKind;

/**
 * negotiates the response media type of a request: `Accept` first, then `Content-Type`,
 * so 406 comes before 415.
 *
 * a route is described by four facts: the extensions it applies,
 * whether a read of it pages its primary data, the media types of the content it reads,
 * and the representations it responds in.
 * a generated route responds with JSON:API alone and reads JSON:API content with its extensions
 * (`extensionsOf()`, `pagesOn()`, `readableOn()`).
 *
 * what is applied depends on the route and on the request's `Accept`, method and page parameters,
 * so requests that negotiate alike share one entity tag.
 * it is decided before any failure is known,
 * so the error response to a cursor-paged reading still names the cursor profile:
 * no MUST forbids that,
 * and avoiding it would mean deriving the media type from the written document
 *
 * @internal
 */
final readonly class Negotiator
{
    /**
     * in `$readable` and `$representations`, the JSON:API media type stands for its documents
     *
     * @param list<string>              $extensions      the extensions the route applies
     * @param bool                      $pages           whether a read pages its primary data
     * @param list<MediaType>           $readable        the content media types the route reads
     * @param non-empty-list<MediaType> $representations the response media types, in declared order
     *
     * @throws NotAcceptableException        when `Accept` admits no representation of the route
     * @throws UnsupportedMediaTypeException when the route reads no content of that media type
     */
    public function negotiate(
        ServerRequest $request,
        array $extensions,
        bool $pages,
        array $readable,
        array $representations,
    ): Negotiation {
        [$picked, $served] = self::pick($request->headers->line(FieldName::ACCEPT), $extensions, $representations);

        self::checkContent($request, $extensions, $readable);

        if (!$picked->isJsonApi()) {
            return new Negotiation($picked);
        }

        $pages = $pages && Method::retrieves($request->method);
        $requested = self::applicable($request, $pages, $served === null ? [] : self::recognized($served->profile()));

        return new Negotiation(
            MediaType::jsonApi($extensions, self::profiles($request, $pages, $requested)),
            $requested,
        );
    }

    /**
     * the negotiation of a generated route, which responds with JSON:API alone:
     * its route kind states the other three facts
     *
     * @throws NotAcceptableException        when `Accept` admits no JSON:API representation
     * @throws UnsupportedMediaTypeException when the route reads no content of that media type
     */
    public function negotiateRoute(ServerRequest $request, RouteKind $route): Negotiation
    {
        return $this->negotiate(
            $request,
            self::extensionsOf($route),
            self::pagesOn($route),
            self::readableOn($route),
            [MediaType::jsonApi()],
        );
    }

    /**
     * the media types of the content a generated route reads: JSON:API with the route's extensions.
     * a generated route of a kind that reads no content, a read or a delete,
     * leaves content of that media type unread rather than refusing it
     *
     * @return list<MediaType>
     */
    public static function readableOn(RouteKind $route): array
    {
        return [MediaType::jsonApi(self::extensionsOf($route))];
    }

    /**
     * the route's extensions, never the client's.
     * the atomic extension named on another route is refused with 406 or 415,
     * and an atomic route applies it even to an `Accept` without it,
     * since `ext` in `Accept` can only require an extension
     *
     * @return list<string>
     */
    public static function extensionsOf(RouteKind $route): array
    {
        return $route === RouteKind::Atomic ? [MediaType::ATOMIC_EXTENSION] : [];
    }

    public static function pagesOn(RouteKind $route): bool
    {
        return match ($route) {
            RouteKind::Collection, RouteKind::Related, RouteKind::Relationship => true,
            RouteKind::Resource, RouteKind::Atomic => false,
        };
    }

    /**
     * the requested profiles, plus the cursor profile on a reading that pages by cursor unasked:
     * the profile defines that pagination, and a response names every profile it applies.
     * the requested ones stay first, so one set always comes in one order for the entity tag
     *
     * @param  bool         $pages     whether the request is a read that pages its primary data
     * @param  list<string> $requested
     * @return list<string>
     */
    private static function profiles(ServerRequest $request, bool $pages, array $requested): array
    {
        if (in_array(CursorProfile::URI, $requested, true) || !self::pagesByCursor($request, $pages)) {
            return $requested;
        }

        return [...$requested, CursorProfile::URI];
    }

    /**
     * drops a requested cursor profile from a reading whose page parameters pick number or offset:
     * explicit parameters win, the spec asks a server only to attempt a requested profile,
     * and its MUSTs on the first item, the page size and the max-size error would not hold.
     * the requested list is narrowed, since it also pages the rest of the document,
     * where a linkage paged by cursor would break the same MUSTs
     *
     * @param  bool         $pages     whether the request is a read that pages its primary data
     * @param  list<string> $requested
     * @return list<string>
     */
    private static function applicable(ServerRequest $request, bool $pages, array $requested): array
    {
        if (
            !in_array(CursorProfile::URI, $requested, true)
            || !$pages
            || PageParser::strategy($request->query, Strategy::Cursor) === Strategy::Cursor
        ) {
            return $requested;
        }

        return array_values(array_filter(
            $requested,
            static function (string $uri): bool {
                return $uri !== CursorProfile::URI;
            },
        ));
    }

    /**
     * the default passed is page number, not the requested strategy:
     * a requested cursor profile is applied already
     */
    private static function pagesByCursor(ServerRequest $request, bool $pages): bool
    {
        return $pages && PageParser::strategy($request->query, Strategy::PageNumber) === Strategy::Cursor;
    }

    /**
     * the representation `Accept` picks, the first declared among those of highest weight above 0,
     * and the JSON:API instance that gave the JSON:API one its weight,
     * whose profiles the client requested.
     *
     * - an absent, empty or malformed field states no preference:
     *   the first representation is picked
     * - a JSON:API instance with a parameter besides `ext` and `profile`,
     *   or with an unsupported extension, is ignored,
     *   and its reason becomes an error object of the 406 if nothing is picked
     * - where the field holds JSON:API instances and every one is ignored,
     *   the response is 406 whatever else it admits:
     *   the MUSTs of the specification cover "all instances of that media type"
     *   and beat the precedence of RFC 9110
     * - the JSON:API representation weighs as its instance of highest weight,
     *   the first written among equals, or, without an instance,
     *   as the range of highest precedence that admits it (RFC 9110 12.5.1);
     *   another representation weighs as that range;
     *   a weight of 0 is "not acceptable" (RFC 9110 12.4.2)
     *
     * @param ?string                   $accept          null when the request carries no such field
     * @param list<string>              $extensions
     * @param non-empty-list<MediaType> $representations
     *
     * @return array{MediaType, ?MediaType}
     *
     * @throws NotAcceptableException when no representation is acceptable
     */
    private static function pick(?string $accept, array $extensions, array $representations): array
    {
        $ranges = $accept === null ? null : MediaType::parseList($accept);

        if ($ranges === null || $ranges === []) {
            return [$representations[0], null];
        }

        $instances = [];
        $others = [];

        foreach ($ranges as $range) {
            if ($range->isJsonApi()) {
                $instances[] = $range;
            } else {
                $others[] = $range;
            }
        }

        $served = null;
        $valid = false;
        $descriptions = [];

        foreach ($instances as $instance) {
            $ignored = self::report($instance, $extensions, FieldName::ACCEPT);

            if ($ignored !== []) {
                $descriptions = [...$descriptions, ...$ignored];

                continue;
            }

            $valid = true;

            if ($instance->weight > 0.0 && ($served === null || $instance->weight > $served->weight)) {
                $served = $instance;
            }
        }

        if ($instances !== [] && !$valid) {
            throw new NotAcceptableException($descriptions);
        }

        $picked = null;
        $best = 0.0;

        foreach ($representations as $representation) {
            if ($representation->isJsonApi()) {
                $weight = $instances === [] ? self::weight($representation, $others) : ($served->weight ?? 0.0);
            } else {
                $weight = self::weight($representation, $others);
            }

            if ($weight > $best) {
                $picked = $representation;
                $best = $weight;
            }
        }

        if ($picked === null) {
            throw new NotAcceptableException($descriptions === [] ? [self::notAcceptable()] : $descriptions);
        }

        return [$picked, $picked->isJsonApi() ? $served : null];
    }

    /**
     * the weight of the range of highest precedence that admits the media type,
     * the first written among equals; 0 where none admits it
     *
     * @param list<MediaType> $ranges
     */
    private static function weight(MediaType $mediaType, array $ranges): float
    {
        $match = null;

        foreach ($ranges as $range) {
            if ($range->admits($mediaType) && ($match === null || $range->specificity() > $match->specificity())) {
                $match = $range;
            }
        }

        return $match->weight ?? 0.0;
    }

    /**
     * a request without content is not checked: 415 refuses a format, and there is none.
     * content of a media type the route reads none of, a missing field, a list or a value with `q`
     * is the generic `unsupported_media_type` (RFC 9110 15.5.16):
     * the content "is in a format not supported by this method on the target resource".
     * JSON:API content on a route that reads it, with a parameter besides `ext` and `profile`,
     * or an unsupported or a missing extension, gets an error object per reason.
     * another media type is read where a readable one admits it: the same type and subtype,
     * and each of its parameters in the content's media type
     *
     * @param list<string>    $extensions
     * @param list<MediaType> $readable
     *
     * @throws UnsupportedMediaTypeException when the route does not read the content's media type
     */
    private static function checkContent(ServerRequest $request, array $extensions, array $readable): void
    {
        if (!$request->hasContent()) {
            return;
        }

        $line = $request->headers->line(FieldName::CONTENT_TYPE);
        $mediaType = $line === null ? null : MediaType::parse($line);

        if ($mediaType !== null && $mediaType->isJsonApi() && self::readsJsonApi($readable)) {
            $descriptions = self::report($mediaType, $extensions, FieldName::CONTENT_TYPE);

            if ($descriptions === []) {
                $descriptions = self::required($mediaType, $extensions);
            }

            if ($descriptions !== []) {
                throw new UnsupportedMediaTypeException($descriptions);
            }

            return;
        }

        foreach ($readable as $candidate) {
            if ($mediaType !== null && !$candidate->isJsonApi() && $candidate->admits($mediaType)) {
                return;
            }
        }

        throw new UnsupportedMediaTypeException([
            new ErrorDescription(ErrorCode::UnsupportedMediaType, header: FieldName::CONTENT_TYPE),
        ]);
    }

    /**
     * @param list<MediaType> $readable
     */
    private static function readsJsonApi(array $readable): bool
    {
        foreach ($readable as $mediaType) {
            if ($mediaType->isJsonApi()) {
                return true;
            }
        }

        return false;
    }

    /**
     * content that omits an extension the route applies
     * is typed as a document it is not: 415 `extension_required`.
     * it is refused here, before preconditions are evaluated,
     * since RFC 9110 has a server ignore preconditions when the request fails without them
     *
     * @param list<string> $extensions
     *
     * @return list<ErrorDescription>
     */
    private static function required(MediaType $content, array $extensions): array
    {
        $descriptions = [];

        foreach ($extensions as $uri) {
            if (!in_array($uri, $content->ext(), true)) {
                $descriptions[] = new ErrorDescription(
                    ErrorCode::ExtensionRequired,
                    ['extension' => $uri],
                    header: FieldName::CONTENT_TYPE,
                );
            }
        }

        return $descriptions;
    }

    /**
     * one error description per reason the instance cannot be applied.
     * a parameter besides `ext` and `profile` voids the whole instance,
     * so its extensions go unreported: the parameter is what the client repairs
     *
     * @param list<string> $extensions
     * @param string       $field      the header the instance came from
     *
     * @return list<ErrorDescription>
     */
    private static function report(MediaType $instance, array $extensions, string $field): array
    {
        $descriptions = [];

        foreach (array_keys($instance->parameters) as $key) {
            $name = (string) $key;

            if ($name !== MediaType::EXT && $name !== MediaType::PROFILE) {
                $descriptions[] = new ErrorDescription(
                    ErrorCode::UnsupportedMediaTypeParameter,
                    ['name' => $name],
                    header: $field,
                );
            }
        }

        if ($descriptions !== []) {
            return $descriptions;
        }

        foreach ($instance->ext() as $uri) {
            if (!in_array($uri, $extensions, true)) {
                $descriptions[] = new ErrorDescription(
                    ErrorCode::UnsupportedExtension,
                    ['extension' => $uri],
                    header: $field,
                );
            }
        }

        return $descriptions;
    }

    /**
     * an unrecognized profile is ignored, as the spec requires;
     * a recognized one may be spelled in any of its spellings, and a response writes its own URI
     *
     * @param list<string> $uris
     *
     * @return list<string>
     */
    private static function recognized(array $uris): array
    {
        $profiles = [];

        foreach ($uris as $uri) {
            $recognized = in_array($uri, CursorProfile::SPELLINGS, true) ? CursorProfile::URI : null;

            if ($recognized !== null && !in_array($recognized, $profiles, true)) {
                $profiles[] = $recognized;
            }
        }

        return $profiles;
    }

    private static function notAcceptable(): ErrorDescription
    {
        return new ErrorDescription(ErrorCode::NotAcceptable, header: FieldName::ACCEPT);
    }
}
