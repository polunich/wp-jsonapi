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

namespace Polunich\WpJsonApi\Routing;

/**
 * finds two routes of one method that one path can match,
 * without comparing each route with each, which costs the square of the number of routes.
 *
 * the templates of one method and one length stand in a tree of their segments,
 * a literal under its encoding in lower case and every parameter under one key,
 * so a template meets only the earlier ones
 * whose literal agrees with its own at every position where both hold a literal.
 * the tree only narrows the pairs: `PathTemplate::overlaps()` decides each pair it leaves,
 * so the rule has one owner
 *
 * @internal
 */
final readonly class TemplateOverlaps
{
    /**
     * the key of a parameter segment;
     * no literal key holds `{`, which `rawurlencode()` encodes
     */
    private const PARAMETER = '{}';

    private function __construct() {}

    /**
     * the first pair of routes that respond to one method and whose templates overlap:
     * the least position of the earlier route, then of the later one,
     * as a loop over every pair meets it.
     * `$ignoringCase` is read as `PathTemplate::overlaps()` reads it
     *
     * @param list<array{list<string>, PathTemplate}> $routes each route's methods and template
     *
     * @return ?array{int, int} the positions of the earlier and the later route,
     *                          null when none overlap
     */
    public static function first(array $routes, bool $ignoringCase): ?array
    {
        /** @var array<string, array<array-key, true>> $literals node => its child literal keys */
        $literals = [];
        /** @var array<string, true> $parameters the nodes with a parameter below them */
        $parameters = [];
        /** @var array<string, list<int>> $ends node => positions of the templates ending there */
        $ends = [];
        $first = null;

        foreach ($routes as $later => [$methods, $template]) {
            $earlier = [];

            foreach (array_unique($methods) as $method) {
                // a method is a token and holds no line feed
                $root = $method . "\n" . count($template->segments);

                foreach (self::reached($root, $template->segments, $literals, $parameters, $ends) as $position) {
                    $earlier[$position] = true;
                }

                $node = $root;

                foreach ($template->segments as $segment) {
                    $key = self::key($segment);

                    if ($key === self::PARAMETER) {
                        $parameters[$node] = true;
                    } else {
                        $literals[$node][$key] = true;
                    }

                    $node .= '/' . $key;
                }

                $ends[$node][] = $later;
            }

            foreach (array_keys($earlier) as $position) {
                // a later route of an earlier pair can never come first
                if (
                    ($first === null || $position < $first[0])
                    && $routes[$position][1]->overlaps($template, $ignoringCase)
                ) {
                    $first = [$position, $later];
                }
            }
        }

        return $first;
    }

    /**
     * the positions of the templates below the root whose literal agrees with the segment
     * of the template at every position where both hold one
     *
     * @param non-empty-list<string|PathParameter>  $segments
     * @param array<string, array<array-key, true>> $literals
     * @param array<string, true>                   $parameters
     * @param array<string, list<int>>              $ends
     *
     * @return list<int>
     */
    private static function reached(
        string $root,
        array $segments,
        array $literals,
        array $parameters,
        array $ends,
    ): array {
        $nodes = [$root];

        foreach ($segments as $segment) {
            $key = self::key($segment);
            $next = [];

            foreach ($nodes as $node) {
                if ($key === self::PARAMETER) {
                    foreach (array_keys($literals[$node] ?? []) as $literal) {
                        $next[] = $node . '/' . $literal;
                    }
                } elseif (isset($literals[$node][$key])) {
                    $next[] = $node . '/' . $key;
                }

                if (isset($parameters[$node])) {
                    $next[] = $node . '/' . self::PARAMETER;
                }
            }

            $nodes = $next;
        }

        $positions = [];

        foreach ($nodes as $node) {
            foreach ($ends[$node] ?? [] as $position) {
                $positions[] = $position;
            }
        }

        return $positions;
    }

    /**
     * two literals that overlap, in either letter case, share their key
     */
    private static function key(string|PathParameter $segment): string
    {
        return $segment instanceof PathParameter ? self::PARAMETER : strtolower(rawurlencode($segment));
    }
}
