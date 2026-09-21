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

namespace Polunich\WpJsonApi\WordPress;

use InvalidArgumentException;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Routing\TemplateOverlaps;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the WordPress route patterns of an API, the normal form of a route,
 * and the route of the library a path lies on.
 *
 * routes of one template share one pattern:
 * WordPress keys a route by its pattern text and appends the handlers of a second registration.
 * a literal stands encoded, as `Routing\PathTemplate` writes it, and unescaped:
 * WordPress's route index publishes the pattern of a route without parameters as the route's link,
 * so the pattern of a literal is its own text.
 * that holds because a literal holds no `.`, which JSON:API reserves in member names
 * and a written path refuses, and `rawurlencode()` leaves only letters, digits and `-_.~`,
 * none of `-_~` and `%` being PCRE syntax outside a character class;
 * a literal holding any other character is refused.
 * a parameter becomes `(?P<name>(?=(?:P)(?:/|\z))[^/]+)`:
 * the lookahead lets the codec's pattern `P` match the whole segment and no more,
 * and the group never spans two segments, whatever `P` is.
 * `\z`, since `$` also matches before a final line feed.
 * WordPress hands every named group over as a URL parameter.
 * each template also has a loose pattern, every parameter `[^/]+`,
 * which tells that a path lies on a route of the library whatever its parameter segments hold.
 *
 * WordPress stores a route as `/<namespace>/<pattern without outer slashes>`
 * and matches a path against it case-insensitively, with `@` as the delimiter
 * and the namespace unescaped, the first match winning.
 * so two routes of one method whose templates overlap when letter case is ignored are refused,
 * and so is a namespace that holds `@` or makes a pattern PCRE does not compile:
 * it would match no path.
 *
 * the normal form writes each segment below the namespace as `rawurlencode()` of its decoded text:
 * every unreserved character as itself, every other octet percent-encoded,
 * the form of every literal and every link.
 * it is one text per segment whichever form the client sent and whichever permalinks the site uses:
 * on `index.php/` permalinks WordPress routes the decoded `PATH_INFO`, with each `%` encoded again.
 * the namespace stays as it is: WordPress puts it into every pattern unescaped
 *
 * @internal
 */
final readonly class RoutePatterns
{
    /** what `rawurlencode()` writes of a literal that holds no `.` */
    private const LITERAL = '/^[A-Za-z0-9%_~-]+$/D';

    /**
     * a namespace of letters, digits, `/`, `-`, `_` and `~` alone,
     * each character of which matches itself in a pattern,
     * ignoring case just as a comparison of the text does
     */
    private const PLAIN_NAMESPACE = '@^[A-Za-z0-9/_~-]+$@D';

    /** @var non-falsy-string */
    public string $namespace;

    /**
     * whether the text of a path tells that it lies below the namespace every pattern starts with
     */
    private bool $plainNamespace;

    /** @var array<non-falsy-string, non-empty-list<Route>> pattern => routes */
    private array $routes;

    /**
     * @var array<non-falsy-string, non-falsy-string> pattern => loose pattern
     */
    private array $loose;

    /**
     * @param non-falsy-string $namespace
     * @param list<Route>      $routes    in the order of the route table
     *
     * @throws InvalidArgumentException when the namespace holds `@`
     *                                  or makes a pattern PCRE does not compile;
     *                                  when a literal holds pattern syntax once encoded;
     *                                  when two routes of one method overlap ignoring letter case
     */
    public function __construct(string $namespace, array $routes)
    {
        if (str_contains($namespace, '@')) {
            throw new InvalidArgumentException(sprintf(
                'the route namespace "%s" holds "@", the delimiter of the pattern WordPress matches a'
                . ' route with, so no route of it would match a path',
                Printable::of($namespace),
            ));
        }

        $this->namespace = $namespace;
        $this->plainNamespace = preg_match(self::PLAIN_NAMESPACE, $namespace) === 1;
        $patterns = [];
        $loose = [];

        foreach ($routes as $route) {
            self::checkLiterals($route);

            $pattern = self::pattern($route, false);
            $patterns[$pattern][] = $route;
            $loose[$pattern] = self::pattern($route, true);
        }

        $this->routes = $patterns;
        $this->loose = $loose;

        $this->checkCompiles();
        self::checkOverlaps($routes);
    }

    /**
     * @return array<non-falsy-string, non-empty-list<Route>>
     */
    public function all(): array
    {
        return $this->routes;
    }

    /**
     * the path with every segment below the namespace in the normal form;
     * a path outside the namespace, compared ignoring case as WordPress does, is returned as it is
     */
    public function normalize(string $path): string
    {
        if (!$this->isBelowNamespace($path)) {
            return $path;
        }

        $length = strlen('/' . $this->namespace . '/');
        $segments = explode('/', substr($path, $length));

        return substr($path, 0, $length) . implode('/', array_map(static function (string $segment): string {
            return rawurlencode(rawurldecode($segment));
        }, $segments));
    }

    /**
     * the normal form of the path when it matches the pattern of a route of the library, else null
     */
    public function normalizedRoute(string $path): ?string
    {
        if (!$this->mayMatch($path)) {
            return null;
        }

        $normalized = $this->normalize($path);

        return $this->matched($normalized, false) === [] ? null : $normalized;
    }

    /**
     * the route of the library a request lies on, read from the normal form of its path:
     * among the patterns the path matches, the first route that responds to the method,
     * as WordPress picks a handler, else the first route of the first pattern.
     * where a codec refuses a parameter segment, the loose patterns stand in.
     *
     * the route of the method names the operation a 401 is challenged for,
     * even of a request WordPress refused before it looked for a handler;
     * any route stands for its whole path, which is all the `Allow` field of a 405 needs
     */
    public function routeFor(string $path, string $method): ?Route
    {
        if (!$this->mayMatch($path)) {
            return null;
        }

        $normalized = $this->normalize($path);
        $patterns = $this->matched($normalized, false);

        if ($patterns === []) {
            $patterns = $this->matched($normalized, true);
        }

        foreach ($patterns as $pattern) {
            foreach ($this->routes[$pattern] as $route) {
                if ($route->respondsTo($method)) {
                    return $route;
                }
            }
        }

        return $patterns === [] ? null : $this->routes[$patterns[0]][0];
    }

    /**
     * compared ignoring case, as a pattern matches
     */
    private function isBelowNamespace(string $path): bool
    {
        $prefix = '/' . $this->namespace . '/';

        return strncasecmp($path, $prefix, strlen($prefix)) === 0;
    }

    /**
     * false where no pattern can match the path,
     * so a lookup for a REST request to another namespace returns without running one.
     * a namespace that is not plain is matched by the patterns alone
     */
    private function mayMatch(string $path): bool
    {
        return !$this->plainNamespace || $this->isBelowNamespace($path);
    }

    /**
     * the patterns a path in the normal form matches, in the order WordPress tries them;
     * `$loose` matches each by its loose pattern
     *
     * @return list<non-falsy-string>
     */
    private function matched(string $normalized, bool $loose): array
    {
        $matched = [];

        foreach ($this->loose as $strict => $looseForm) {
            if (preg_match($this->regex($loose ? $looseForm : $strict), $normalized) === 1) {
                $matched[] = $strict;
            }
        }

        return $matched;
    }

    /**
     * the pattern of the template, or its loose pattern, below the namespace
     *
     * @return non-falsy-string
     */
    private static function pattern(Route $route, bool $loose): string
    {
        $segments = array_map(static function (string|PathParameter $segment) use ($loose): string {
            if (!$segment instanceof PathParameter) {
                return rawurlencode($segment);
            }

            return $loose
                ? '(?P<' . $segment->name . '>[^/]+)'
                : '(?P<' . $segment->name . '>(?=(?:' . $segment->codec->segmentPattern() . ')(?:/|\z))[^/]+)';
        }, $route->template->segments);

        return '/' . implode('/', $segments);
    }

    /**
     * @throws InvalidArgumentException when an encoded literal holds a character
     *                                  other than a letter, a digit, `%`, `-`, `_` or `~`
     */
    private static function checkLiterals(Route $route): void
    {
        foreach ($route->template->segments as $segment) {
            if ($segment instanceof PathParameter || preg_match(self::LITERAL, rawurlencode($segment)) === 1) {
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'the route "%s" holds the literal "%s", which is no pattern of its own text: WordPress matches a'
                . ' route by its pattern and publishes the pattern of a route without parameters as its link, so'
                . ' a literal holds letters, digits, "-", "_" and "~" once encoded',
                Printable::of($route->template->path),
                Printable::of($segment),
            ));
        }
    }

    /**
     * @throws InvalidArgumentException when a pattern does not compile
     */
    private function checkCompiles(): void
    {
        foreach (array_keys($this->routes) as $pattern) {
            if (!self::compiles($this->regex($pattern))) {
                throw new InvalidArgumentException(sprintf(
                    'the route "%s" is no pattern PCRE compiles, so WordPress would match no path with it',
                    Printable::of($this->routeText($pattern)),
                ));
            }
        }
    }

    /**
     * the overlap test of `Metadata\RouteTable` again, ignoring letter case as WordPress does
     *
     * @param list<Route> $routes
     *
     * @throws InvalidArgumentException when two routes of one method overlap
     */
    private static function checkOverlaps(array $routes): void
    {
        $overlap = TemplateOverlaps::first(array_map(static function (Route $route): array {
            return [$route->methods, $route->template];
        }, $routes), true);

        if ($overlap === null) {
            return;
        }

        $route = $routes[$overlap[0]];
        $later = $routes[$overlap[1]];

        throw new InvalidArgumentException(sprintf(
            'the routes "%s" and "%s" both respond to %s, and WordPress matches a route ignoring letter'
            . ' case, the first registered winning, so a path that matches both could never reach'
            . ' the second',
            Printable::of($route->template->path),
            Printable::of($later->template->path),
            $route->sharedMethod($later),
        ));
    }

    private function routeText(string $pattern): string
    {
        return '/' . $this->namespace . '/' . trim($pattern, '/');
    }

    private function regex(string $pattern): string
    {
        return '@^' . $this->routeText($pattern) . '$@i';
    }

    /**
     * without the warning `preg_match()` raises for an expression it cannot compile
     */
    private static function compiles(string $regex): bool
    {
        set_error_handler(static function (): bool {
            return true;
        });

        try {
            return preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
