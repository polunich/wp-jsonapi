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

use InvalidArgumentException;
use Polunich\WpJsonApi\Codec\SegmentCodec;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the path of a route below the REST namespace, as literal segments and parameter segments.
 *
 * a literal holds its text, such as `pets` or `relationships`, unencoded:
 * every writer of a path percent-encodes it with `rawurlencode()`.
 * `rawurlencode()` leaves letters, digits and `-_.~` as they are
 * and writes every other octet as `%` and two upper-case hex digits,
 * so a text and its encoding determine each other,
 * and the encoding is the form in which a codec's pattern sees a segment (`Codec\SegmentCodec`).
 *
 * `$path` is the template as OpenAPI path templating writes it,
 * each literal encoded and each parameter as its name in braces, `/pet%20toys/{pet_id}`.
 * a literal writes `{` and `}` encoded, so every brace of the path belongs to a parameter
 *
 * @internal
 */
final readonly class PathTemplate
{
    private const WRITTEN_LITERAL = '/^[A-Za-z0-9_~-]+$/D';

    public string $path;

    /**
     * @param non-empty-list<string|PathParameter> $segments in path order
     *
     * @throws InvalidArgumentException when a literal is empty, or two parameters share a name
     */
    public function __construct(public array $segments)
    {
        $written = [];
        $names = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException(
                    'a path template holds an empty literal segment, and a path segment of a route is never empty',
                );
            }

            if ($segment instanceof PathParameter) {
                if (array_key_exists($segment->name, $names)) {
                    throw new InvalidArgumentException(sprintf(
                        'a path template names the parameter "%s" twice, and a request hands over one value per'
                        . ' name',
                        $segment->name,
                    ));
                }

                $names[$segment->name] = true;
                $written[] = '{' . $segment->name . '}';

                continue;
            }

            $written[] = rawurlencode($segment);
        }

        $this->path = '/' . implode('/', $written);
    }

    /**
     * reads a path a project writes, such as `/orders/{order_id}/invoice`.
     * a parameter takes a whole segment, written as its name in braces.
     * a literal holds letters, digits, `-`, `_` and `~` alone:
     * unreserved characters of RFC 3986, which every URL form of the site writes as they are.
     * `.`, unreserved as well, is refused: a route pattern would read it as any character,
     * and without it a literal stands in a pattern as its own text
     *
     * @param array<string, SegmentCodec> $codecs the codec of every parameter of the path, by name
     * @param string                      $owner  what writes the path, for the message,
     *                                            such as `the endpoint "exportOrders"`
     *
     * @throws InvalidArgumentException when the path does not begin with `/`,
     *                                  holds an empty segment or a literal with another character,
     *                                  or a parameter without a codec or whose name repeats
     *                                  or breaks the rule of a group name;
     *                                  or when a codec names no parameter of the path
     */
    public static function written(string $path, array $codecs, string $owner): self
    {
        $shown = Printable::of($path);

        if (!str_starts_with($path, '/') || $path === '/') {
            throw new InvalidArgumentException(sprintf(
                '%s writes the path "%s", and a path begins with "/" and names one segment at least',
                $owner,
                $shown,
            ));
        }

        $segments = [];

        foreach (explode('/', substr($path, 1)) as $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException(sprintf(
                    '%s writes the path "%s", which holds an empty segment, and a segment of a route is never empty',
                    $owner,
                    $shown,
                ));
            }

            if (preg_match('/^\{([^{}]*)\}$/D', $segment, $match) === 1) {
                $name = $match[1];
                PathParameter::checkName($name, sprintf('a parameter of the path "%s" of %s', $shown, $owner));

                $codec = $codecs[$name] ?? throw new InvalidArgumentException(sprintf(
                    '%s writes the parameter "%s" in its path and declares no codec for it and binds it to no type',
                    $owner,
                    $name,
                ));
                $segments[] = new PathParameter($name, $codec);

                continue;
            }

            if (preg_match(self::WRITTEN_LITERAL, $segment) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    '%s writes the segment "%s" in its path, and a literal segment holds letters, digits, "-", "_" and'
                    . ' "~" alone: characters every URL form of the site writes as they are, of which WordPress'
                    . ' matches the route by its own text',
                    $owner,
                    Printable::of($segment),
                ));
            }

            $segments[] = $segment;
        }

        $template = new self($segments);
        $named = array_map(static function (PathParameter $parameter): string {
            return $parameter->name;
        }, $template->parameters());

        foreach (array_keys($codecs) as $name) {
            if (!in_array((string) $name, $named, true)) {
                throw new InvalidArgumentException(sprintf(
                    '%s declares the parameter "%s", which its path "%s" does not hold',
                    $owner,
                    Printable::of((string) $name),
                    $shown,
                ));
            }
        }

        return $template;
    }

    /**
     * the segments of the path this template gives for the values of its parameters,
     * each value as given: the writer of a URL percent-encodes every segment
     *
     * @param array<string, string> $values by the name of the parameter
     *
     * @return non-empty-list<string>
     *
     * @throws InvalidArgumentException when a parameter of the template has no value
     */
    public function fill(array $values): array
    {
        return array_map(function (string|PathParameter $segment) use ($values): string {
            if (!$segment instanceof PathParameter) {
                return $segment;
            }

            return $values[$segment->name] ?? throw new InvalidArgumentException(sprintf(
                'the path "%s" names the parameter "%s", and no value of it is given',
                $this->path,
                $segment->name,
            ));
        }, $this->segments);
    }

    /**
     * @return list<PathParameter> in path order
     */
    public function parameters(): array
    {
        return array_values(array_filter($this->segments, static function (string|PathParameter $segment): bool {
            return $segment instanceof PathParameter;
        }));
    }

    /**
     * whether one path can match both templates of one length, tested segment by segment:
     * two literals overlap when their encodings are equal,
     * a literal and a parameter when the codec's pattern matches the encoded literal,
     * and two parameters always, since the patterns of two codecs cannot be compared
     * and the rule has to stay decidable.
     *
     * `$ignoringCase` compares the literals and runs the patterns without regard to letter case,
     * as WordPress matches a route
     */
    public function overlaps(self $other, bool $ignoringCase): bool
    {
        if (count($this->segments) !== count($other->segments)) {
            return false;
        }

        foreach ($this->segments as $position => $segment) {
            if (!self::segmentsOverlap($segment, $other->segments[$position], $ignoringCase)) {
                return false;
            }
        }

        return true;
    }

    /**
     * the path with every parameter unnamed, `/pets/{}`:
     * two templates lie on one hierarchy exactly when theirs are equal.
     * OpenAPI counts two such templates as one path:
     * "Templated paths with the same hierarchy but different templated names MUST NOT exist
     * as they are identical" (OpenAPI 3.1.2, section 4.8.8.1)
     */
    public function hierarchy(): string
    {
        return '/' . implode('/', array_map(static function (string|PathParameter $segment): string {
            return $segment instanceof PathParameter ? '{}' : rawurlencode($segment);
        }, $this->segments));
    }

    private static function segmentsOverlap(
        string|PathParameter $segment,
        string|PathParameter $opposite,
        bool $ignoringCase,
    ): bool {
        if ($segment instanceof PathParameter && $opposite instanceof PathParameter) {
            return true;
        }

        if ($segment instanceof PathParameter) {
            return self::admits($segment, $opposite, $ignoringCase);
        }

        if ($opposite instanceof PathParameter) {
            return self::admits($opposite, $segment, $ignoringCase);
        }

        return $ignoringCase
            ? strcasecmp(rawurlencode($segment), rawurlencode($opposite)) === 0
            : $segment === $opposite;
    }

    /**
     * the pattern is anchored and compiled inside `@` delimiters,
     * which `SegmentPattern` checks it for
     */
    private static function admits(PathParameter $parameter, string $literal, bool $ignoringCase): bool
    {
        $regex = '@^(?:' . $parameter->codec->segmentPattern() . ')$@D' . ($ignoringCase ? 'i' : '');

        return preg_match($regex, rawurlencode($literal)) === 1;
    }
}
