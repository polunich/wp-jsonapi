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

namespace Polunich\WpJsonApi\Rendering;

use Closure;
use InvalidArgumentException;
use LogicException;
use Polunich\WpJsonApi\Document\Links;
use Polunich\WpJsonApi\Document\Member;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\CursorText;
use Polunich\WpJsonApi\Query\Pagination\PageMeta;
use Polunich\WpJsonApi\Query\Pagination\PageParameter;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\RouteTarget;
use UnexpectedValueException;

/**
 * the pagination links and `meta.page` of one page.
 *
 * the links are appended to the links object given, so one place fixes the member order:
 * self, related, first, prev, next, last.
 *
 * an unavailable link is null or absent, and the two mean different things here.
 * null: the library knows there is no such page; prev and next are always written,
 * as the cursor pagination profile requires.
 * absent: the library cannot tell; last without a total, and first and last on a cursor page,
 * since the library holds no cursor of a page it has not read.
 * a null there would claim there is no such page.
 *
 * nothing is computed from the records: `Page` states which links exist and their cursors,
 * so an empty cursor page links like any other.
 *
 * every link repeats the used page size, even one the client did not send,
 * so the size cannot drift during a walk
 *
 * @internal
 */
final readonly class PaginationWriter
{
    public function __construct(private RouteUrls $urls, private NamingPolicy $naming) {}

    /**
     * `$route` and `$query` are those of the self link the page belongs to:
     * the route of the request with its `LinkQuery` for primary data,
     * the relationship route with the language parameter alone for a relationship object
     *
     * @param array<array-key, mixed> $query
     *
     * @throws LogicException           when the route is the resource or an atomic route,
     *                                  no route responds to a GET of it,
     *                                  or the page lacks a member its strategy needs
     * @throws UnexpectedValueException when a boundary cursor cannot be written as text
     */
    public function links(Links $links, Page $page, RouteTarget $route, array $query): Links
    {
        // checked up front: a cursor page without neighbours builds no URL,
        // which would let a wrong route pass
        if ($route->kind === RouteKind::Resource || $route->kind === RouteKind::Atomic) {
            throw self::unpaginated($route->kind);
        }

        return $this->written(
            $links,
            $page,
            sprintf('the %s route', $route->kind->value),
            $query,
            function (array $query) use ($route): string {
                return $this->urls->fetchUrl($route, $query);
            },
        );
    }

    /**
     * the links of a page an endpoint responds with lead to the URL of the endpoint,
     * `$path` and `$query` being those of its self link
     *
     * @param array<string, string>   $path  the path parameters of the request
     * @param array<array-key, mixed> $query
     *
     * @throws InvalidArgumentException when the path holds no value of a parameter of the endpoint
     * @throws LogicException           when the page lacks a member its strategy needs
     * @throws UnexpectedValueException when a boundary cursor cannot be written as text
     */
    public function endpointLinks(
        Links $links,
        Page $page,
        EndpointMetadata $endpoint,
        array $path,
        array $query,
    ): Links {
        return $this->written(
            $links,
            $page,
            sprintf('the endpoint "%s"', $endpoint->name),
            $query,
            function (array $query) use ($endpoint, $path): string {
                return $this->urls->endpointUrl($endpoint, $path, $query);
            },
        );
    }

    /**
     * the members of `meta.page`, empty when the page reports nothing.
     * the truncated range is written only as true,
     * the one value the cursor pagination profile asks for
     *
     * @return array<string, mixed>
     */
    public function metadata(Page $page): array
    {
        $metadata = [];

        if ($page->total !== null) {
            $metadata[PageMeta::Total->nameIn($this->naming)] = $page->total;
        }

        if ($page->estimatedTotal !== null) {
            $metadata[PageMeta::EstimatedTotal->nameIn($this->naming)] = [
                PageMeta::BestGuess->nameIn($this->naming) => $page->estimatedTotal,
            ];
        }

        if ($page->rangeTruncated) {
            $metadata[PageMeta::RangeTruncated->nameIn($this->naming)] = true;
        }

        return $metadata;
    }

    /**
     * @param string                                  $place what the page was read on
     * @param array<array-key, mixed>                 $query
     * @param Closure(array<array-key, mixed>): string $url   the URL of a page with a query
     *
     * @throws LogicException           when the page lacks a member its strategy needs
     * @throws UnexpectedValueException when a boundary cursor cannot be written as text
     */
    private function written(Links $links, Page $page, string $place, array $query, Closure $url): Links
    {
        $members = $links->all();

        foreach ($this->families($page, $place) as $name => $family) {
            $members[$name] = $family === null ? null : $url($this->queryOf($query, $family));
        }

        return new Links($members);
    }

    /**
     * link name => the `page` members of its target page, null or absent as the class describes
     *
     * @return array<string, non-empty-array<string, int|string>|null>
     *
     * @throws LogicException           when the page lacks a member its strategy needs
     * @throws UnexpectedValueException when a boundary cursor cannot be written as text
     */
    private function families(Page $page, string $place): array
    {
        return match ($page->strategy) {
            Strategy::PageNumber => $this->numbered($page),
            Strategy::Offset => $this->offsets($page),
            Strategy::Cursor => $this->cursors($page, $place),
        };
    }

    /**
     * @return array<string, non-empty-array<string, int|string>|null>
     *
     * @throws LogicException when the page carries no `page[number]`
     */
    private function numbered(Page $page): array
    {
        $size = $page->size;
        $number = $page->number;

        $sizeName = Strategy::PageNumber->sizeParameter()->nameIn($this->naming);
        $numberName = PageParameter::Number->nameIn($this->naming);
        $unfilled = ParameterFamily::Page->parameter($numberName);

        $families = [Member::FIRST => [$numberName => 1, $sizeName => $size]];

        $families[Member::PREV] = $page->hasPrev
            ? [$numberName => ($number ?? throw self::unfilled($page, $unfilled)) - 1, $sizeName => $size]
            : null;

        $families[Member::NEXT] = $page->hasNext
            ? [$numberName => ($number ?? throw self::unfilled($page, $unfilled)) + 1, $sizeName => $size]
            : null;

        if ($page->total !== null) {
            // an empty collection still has page 1. `intdiv()` over `ceil($total / $size)`:
            // a total beyond float precision would round to another page
            $last = $page->total === 0 ? 1 : intdiv($page->total - 1, $size) + 1;

            $families[Member::LAST] = [$numberName => $last, $sizeName => $size];
        }

        return $families;
    }

    /**
     * first and last lie on a grid of page-size steps from offset 0; prev stops at offset 0
     *
     * @return array<string, non-empty-array<string, int|string>|null>
     *
     * @throws LogicException when the page carries no `page[offset]`
     */
    private function offsets(Page $page): array
    {
        $size = $page->size;
        $offset = $page->offset;

        $sizeName = Strategy::Offset->sizeParameter()->nameIn($this->naming);
        $offsetName = PageParameter::Offset->nameIn($this->naming);
        $unfilled = ParameterFamily::Page->parameter($offsetName);

        $families = [Member::FIRST => [$offsetName => 0, $sizeName => $size]];

        $families[Member::PREV] = $page->hasPrev
            ? [
                $offsetName => max(0, ($offset ?? throw self::unfilled($page, $unfilled)) - $size),
                $sizeName => $size,
            ]
            : null;

        $families[Member::NEXT] = $page->hasNext
            ? [
                $offsetName => ($offset ?? throw self::unfilled($page, $unfilled)) + $size,
                $sizeName => $size,
            ]
            : null;

        if ($page->total !== null) {
            $last = $page->total === 0 ? 0 : intdiv($page->total - 1, $size) * $size;

            $families[Member::LAST] = [$offsetName => $last, $sizeName => $size];
        }

        return $families;
    }

    /**
     * @return array<string, non-empty-array<string, int|string>|null>
     *
     * @throws LogicException           when the page lacks the boundary cursor of a link it writes
     * @throws UnexpectedValueException when a boundary cursor cannot be written as text
     */
    private function cursors(Page $page, string $place): array
    {
        $size = $page->size;
        $sizeName = Strategy::Cursor->sizeParameter()->nameIn($this->naming);

        return [
            Member::PREV => $page->hasPrev
                ? [
                    PageParameter::Before->nameIn($this->naming) => self::text(
                        $page->firstCursor ?? throw self::unfilled($page, 'the cursor of its first record'),
                        Member::PREV,
                        $place,
                    ),
                    $sizeName => $size,
                ]
                : null,
            Member::NEXT => $page->hasNext
                ? [
                    PageParameter::After->nameIn($this->naming) => self::text(
                        $page->lastCursor ?? throw self::unfilled($page, 'the cursor of its last record'),
                        Member::NEXT,
                        $place,
                    ),
                    $sizeName => $size,
                ]
                : null,
        ];
    }

    /**
     * the query with the position members the client wrote replaced by `$family`;
     * every other page member, such as the one that asks for the total, is repeated as written.
     * a member named with digits arrives under an int key, hence the string cast
     *
     * @param array<array-key, mixed>             $query
     * @param non-empty-array<string, int|string> $family the members of the target page
     *
     * @return array<array-key, mixed>
     */
    private function queryOf(array $query, array $family): array
    {
        $written = ParameterFamily::Page->valueIn($query);
        $page = [];

        if (is_array($written)) {
            foreach ($written as $member => $value) {
                if (PageParameter::named((string) $member, $this->naming)?->isPosition() !== true) {
                    $page[$member] = $value;
                }
            }
        }

        foreach ($family as $member => $value) {
            $page[$member] = $value;
        }

        $query[ParameterFamily::Page->value] = $page;

        return $query;
    }

    /**
     * a cursor holds sort values read from project records,
     * so a cursor `CursorText` cannot write is a fault of the project, not of the client,
     * hence `UnexpectedValueException`
     *
     * @param string $boundary the link the cursor is written into
     * @param string $place    what the page was read on
     *
     * @throws UnexpectedValueException when the cursor cannot be written as text
     */
    private static function text(Cursor $cursor, string $boundary, string $place): string
    {
        try {
            return CursorText::encode($cursor);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException(sprintf(
                'the boundary cursor of the %s link of a page of %s cannot be written as text: %s',
                $boundary,
                $place,
                $exception->getMessage(),
            ), 0, $exception);
        }
    }

    /**
     * `Page` does not require the members its strategy writes links from; a missing one is a bug,
     * never a null link, which would claim there is no such page
     */
    private static function unfilled(Page $page, string $member): LogicException
    {
        return new LogicException(sprintf(
            'a page of the %s strategy carries no %s, and the links of that strategy are written from'
            . ' it [profile-cursor]',
            $page->strategy->value,
            $member,
        ));
    }

    private static function unpaginated(RouteKind $kind): LogicException
    {
        return new LogicException(sprintf(
            'the %s route carries no paginated data, and pagination links appear in the links object'
            . ' that corresponds to a collection [spec, Pagination]',
            $kind->value,
        ));
    }
}
